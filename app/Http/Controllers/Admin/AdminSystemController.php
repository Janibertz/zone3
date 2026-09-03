<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\StravaAccount;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Systemstatus — läuft die Maschine, nicht: läuft das Geschäft.
 *
 * Der Admin-Bereich beantwortete bisher nur die zweite Frage: Nutzer,
 * Aktivitäten, KI-Kosten, Coach-Verteilung. Zweimal in einer Woche stand
 * hier aber die erste an, und beide Male gab es keine Antwort:
 *
 *   · Nach dem Umbau des Strava-Webhooks kamen keine Aktivitäten mehr
 *     herein. Ob der Job überhaupt lief, ob er fehlschlug und woran —
 *     nirgends sichtbar. Die Ursache war ein Rückstau in der Queue, und
 *     eine Zahl auf dieser Seite hätte sie in Sekunden gezeigt.
 *   · Im Trainingsplan fehlten zwei Tage komplett. Der Verlauf meldete
 *     brav eine Änderung, weil er aus der Modellausgabe geschrieben wird
 *     und nicht aus dem, was in der Datenbank landet.
 *
 * Deshalb steht hier durchgehend, was TATSÄCHLICH in der Datenbank ist,
 * nie, was vorgesehen war.
 */
class AdminSystemController extends Controller
{
    /** Ab wann eine wartende Aufgabe verdächtig ist. */
    private const STALE_JOB_MINUTES = 10;

    /** Ab wann eine laufende Plangenerierung als hängend gilt. */
    private const STUCK_GENERATION_MINUTES = 20;

    public function index(): Response
    {
        return Inertia::render('Admin/System/Index', [
            'queues'      => $this->queues(),
            'failedJobs'  => $this->failedJobs(),
            'planHealth'  => $this->planHealth(),
            'integrations' => $this->integrations(),
            'environment' => $this->environment(),
        ]);
    }

    // ── Queue ────────────────────────────────────────────────────────────

    /**
     * Wartende Aufgaben je Queue.
     *
     * `imports` und `default` werden von getrennten Workern bedient, und
     * genau diese Trennung war die Lösung des Webhook-Problems. Steht die
     * älteste wartende Aufgabe einer Queue seit Minuten, arbeitet ihr
     * Worker nicht — das ist die Zahl, auf die es ankommt, nicht die Menge.
     *
     * @return list<array<string, mixed>>
     */
    private function queues(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        $rows = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as pending, MIN(available_at) as oldest, SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as reserved')
            ->groupBy('queue')
            ->get();

        // Auch eine leere Queue gehört auf die Seite: „0 wartend" ist eine
        // Aussage, eine fehlende Zeile ist keine.
        $known = collect(['default', 'imports'])
            ->merge($rows->pluck('queue'))
            ->unique();

        return $known->map(function (string $queue) use ($rows) {
            $row     = $rows->firstWhere('queue', $queue);
            $oldest  = $row?->oldest ? Carbon::createFromTimestamp($row->oldest) : null;
            $waiting = $oldest ? (int) $oldest->diffInMinutes(now()) : 0;

            return [
                'queue'          => $queue,
                'pending'        => (int) ($row->pending ?? 0),
                'reserved'       => (int) ($row->reserved ?? 0),
                'oldest_at'      => $oldest?->toIso8601String(),
                'waiting_min'    => $waiting,
                'stale'          => $row && $waiting >= self::STALE_JOB_MINUTES,
            ];
        })->values()->all();
    }

    /**
     * Fehlgeschlagene Aufgaben, mit der ersten Zeile der Ausnahme.
     *
     * Der vollständige Stacktrace steht in der Detailansicht; hier zählt,
     * WAS fehlschlägt und wie oft — eine Klasse, die zwanzigmal auftaucht,
     * ist ein Fehler, nicht Pech.
     *
     * @return array<string, mixed>
     */
    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['total' => 0, 'recent' => [], 'byClass' => []];
        }

        $recent = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(function ($row) {
                $payload = json_decode($row->payload, true) ?: [];

                return [
                    'id'        => $row->id,
                    'uuid'      => $row->uuid,
                    'queue'     => $row->queue,
                    'job'       => $payload['displayName'] ?? 'Unbekannt',
                    'failed_at' => $row->failed_at,
                    'reason'    => $this->firstLine($row->exception),
                ];
            });

        $byClass = DB::table('failed_jobs')
            ->get(['payload'])
            ->groupBy(fn ($r) => json_decode($r->payload, true)['displayName'] ?? 'Unbekannt')
            ->map->count()
            ->sortDesc()
            ->take(8)
            ->map(fn ($count, $job) => ['job' => $job, 'count' => $count])
            ->values();

        return [
            'total'   => DB::table('failed_jobs')->count(),
            'recent'  => $recent->all(),
            'byClass' => $byClass->all(),
        ];
    }

    private function firstLine(?string $exception): string
    {
        $line = strtok((string) $exception, "\n") ?: '';

        return mb_substr(trim($line), 0, 200);
    }

    // ── Plan-Gesundheit ──────────────────────────────────────────────────

    /**
     * Was mit den Trainingsplänen nicht stimmt.
     *
     * Die Lückensuche kommt ohne Gerüst und ohne Modell aus: ein Loch ist
     * ein Datum INNERHALB der eigenen Spanne eines Plans, an dem keine
     * einzige Einheit steht. Genau so sahen die beiden gemeldeten Fälle
     * aus — Dienstag belegt, Mittwoch belegt, Donnerstag nichts.
     *
     * @return array<string, mixed>
     */
    private function planHealth(): array
    {
        $today = now()->startOfDay();

        $sessions = TrainingSession::query()
            ->whereHas('trainingPlan', fn ($q) => $q->where('is_active', true))
            ->whereDate('planned_date', '>=', $today->copy()->subDays(7)->toDateString())
            ->get(['id', 'user_id', 'planned_date']);

        $gaps = [];

        foreach ($sessions->groupBy('user_id') as $userId => $ofUser) {
            $dates = $ofUser->map(fn ($s) => $s->planned_date->format('Y-m-d'))->unique()->sort()->values();

            if ($dates->count() < 2) {
                continue;
            }

            $cursor = Carbon::parse($dates->first());
            $last   = Carbon::parse($dates->last());
            $have   = $dates->flip();
            $holes  = [];

            for (; $cursor->lte($last); $cursor->addDay()) {
                $key = $cursor->format('Y-m-d');
                if (! isset($have[$key])) {
                    $holes[] = $key;
                }
            }

            if ($holes !== []) {
                $gaps[] = [
                    'user_id' => $userId,
                    'name'    => User::find($userId)?->name ?? "Nutzer {$userId}",
                    'dates'   => $holes,
                    'count'   => count($holes),
                ];
            }
        }

        usort($gaps, fn ($a, $b) => $b['count'] <=> $a['count']);

        // Einheiten ohne Plan sind da und trotzdem unsichtbar: die Planseite
        // lädt nur Einheiten des aktiven Plans.
        $orphans = TrainingSession::whereNull('training_plan_id');

        $stuck = TrainingPlan::query()
            ->join('events', 'events.id', '=', 'training_plans.event_id')
            ->whereNotNull('events.plan_generating_at')
            ->where('events.plan_generating_at', '<', now()->subMinutes(self::STUCK_GENERATION_MINUTES))
            ->get(['events.id as event_id', 'events.name', 'events.plan_generating_at', 'training_plans.user_id'])
            ->map(fn ($r) => [
                'event_id' => $r->event_id,
                'user_id'  => $r->user_id,
                'name'     => $r->name,
                'since'    => $r->plan_generating_at,
            ])->all();

        return [
            'gaps'           => $gaps,
            'orphans_total'  => (clone $orphans)->count(),
            'orphans_planned' => (clone $orphans)->where('status', 'planned')->count(),
            'stuck'          => $stuck,
        ];
    }

    // ── Anbindungen ──────────────────────────────────────────────────────

    /**
     * Strava und Garmin je Athlet.
     *
     * Die Frage, die eine Woche lang offen war, lautete: kommt bei diesem
     * Athleten überhaupt noch etwas herein? Die Antwort ist das Datum der
     * zuletzt importierten Aktivität — nicht der Verbindungsstatus, denn
     * der bleibt auch dann grün, wenn seit Tagen nichts mehr ankommt.
     *
     * @return array<string, mixed>
     */
    private function integrations(): array
    {
        $lastActivity = Activity::selectRaw('user_id, MAX(start_date) as last_at, COUNT(*) as total')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $lastImport = Activity::selectRaw('user_id, MAX(created_at) as imported_at')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $strava = StravaAccount::with('user:id,name')
            ->get()
            ->map(function (StravaAccount $a) use ($lastActivity, $lastImport) {
                $expired = $a->token_expires_at && $a->token_expires_at->isPast();

                return [
                    'user_id'      => $a->user_id,
                    'name'         => $a->user?->name ?? "Nutzer {$a->user_id}",
                    'strava_id'    => $a->strava_id,
                    'token_expired' => $expired,
                    'expires_at'   => $a->token_expires_at?->toIso8601String(),
                    'last_activity_at' => $lastActivity[$a->user_id]->last_at ?? null,
                    'last_import_at'   => $lastImport[$a->user_id]->imported_at ?? null,
                    'activities'   => (int) ($lastActivity[$a->user_id]->total ?? 0),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        $garmin = [];
        if (Schema::hasTable('garmin_daily_metrics')) {
            $garmin = DB::table('garmin_daily_metrics')
                ->selectRaw('user_id, MAX(`date`) as last_date, COUNT(*) as days')
                ->groupBy('user_id')
                ->get()
                ->map(fn ($r) => [
                    'user_id'   => $r->user_id,
                    'name'      => User::find($r->user_id)?->name ?? "Nutzer {$r->user_id}",
                    'last_date' => $r->last_date,
                    'days'      => (int) $r->days,
                ])->all();
        }

        return ['strava' => $strava, 'garmin' => $garmin];
    }

    // ── Umgebung ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function environment(): array
    {
        $dbSize = null;

        // information_schema gibt es nur in MySQL — unter SQLite (Tests)
        // liefe die Abfrage auf einen Fehler statt auf eine leere Antwort.
        if (DB::connection()->getDriverName() === 'mysql') {
            try {
                $dbSize = (float) DB::selectOne(
                    'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS mb
                     FROM information_schema.tables WHERE table_schema = DATABASE()'
                )->mb;
            } catch (\Throwable) {
                $dbSize = null;
            }
        }

        return [
            'php'          => PHP_VERSION,
            'laravel'      => app()->version(),
            'env'          => app()->environment(),
            'debug'        => (bool) config('app.debug'),
            'queue_driver' => config('queue.default'),
            'cache_driver' => config('cache.default'),
            'db_driver'    => DB::connection()->getDriverName(),
            'db_size_mb'   => $dbSize,
            'model'        => config('services.openai.model'),
            'model_mini'   => config('services.openai.model_mini'),
        ];
    }

    // ── Eingriffe ────────────────────────────────────────────────────────

    public function retryFailed(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('success', 'Aufgabe erneut eingereiht.');
    }

    public function retryAllFailed(): RedirectResponse
    {
        $count = DB::table('failed_jobs')->count();
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('success', "{$count} Aufgaben erneut eingereiht.");
    }

    public function forgetFailed(string $uuid): RedirectResponse
    {
        DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        return back()->with('success', 'Eintrag entfernt.');
    }

    public function flushFailed(): RedirectResponse
    {
        $count = DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->truncate();

        return back()->with('success', "{$count} Einträge entfernt.");
    }

    /**
     * Löcher im Plan eines Athleten mit Ruhetagen schliessen.
     *
     * Bewusst ohne Modellaufruf: das kostet nichts und behauptet nichts.
     * Ein Ruhetag sagt „hier steht kein Training", ein Loch sagt gar nichts
     * und sieht in der App aus wie ein Fehler. Wer stattdessen echte
     * Einheiten will, lässt den Plan neu berechnen.
     */
    public function fillPlanGaps(User $user): RedirectResponse
    {
        $plan = TrainingPlan::where('user_id', $user->id)->where('is_active', true)->latest()->first();

        if (! $plan) {
            return back()->with('error', 'Der Athlet hat keinen aktiven Plan.');
        }

        $dates = TrainingSession::where('training_plan_id', $plan->id)
            ->pluck('planned_date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values();

        if ($dates->count() < 2) {
            return back()->with('error', 'Zu wenige Einheiten, um eine Lücke zu erkennen.');
        }

        $have   = $dates->flip();
        $cursor = Carbon::parse($dates->first());
        $last   = Carbon::parse($dates->last());
        $filled = 0;

        for (; $cursor->lte($last); $cursor->addDay()) {
            $key = $cursor->format('Y-m-d');
            if (isset($have[$key])) {
                continue;
            }

            TrainingSession::create([
                'user_id'          => $user->id,
                'training_plan_id' => $plan->id,
                'event_id'         => $plan->event_id,
                'planned_date'     => $key,
                'type'             => 'rest',
                'title'            => 'Ruhetag',
                'description'      => 'Nachgetragen — an diesem Tag stand nichts im Plan.',
                'intensity'        => 'low',
                'status'           => 'planned',
                'sort_order'       => 0,
            ]);

            $filled++;
        }

        return back()->with('success', $filled === 0
            ? 'Keine Lücken gefunden.'
            : "{$filled} Lücke(n) mit Ruhetagen geschlossen.");
    }
}
