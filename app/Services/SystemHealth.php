<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\StravaAccount;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Was der Betrieb ueber sich selbst weiss.
 *
 * Dieselben Zahlen stehen an zwei Stellen: ausfuehrlich auf /admin/system
 * und als eine Warnzeile auf dem Admin-Dashboard. Sie zweimal zu berechnen
 * hiesse, zwei Wahrheiten zu haben — die haeufigste Fehlerquelle in diesem
 * Projekt. Deshalb hier, einmal.
 *
 * Gelesen wird durchgehend die Datenbank, nie eine Absicht. Genau daran
 * scheiterte die Fehlersuche zweimal: der Plan-Verlauf meldete Aenderungen
 * fuer Tage, an denen nie eine Einheit stand, weil er aus der Modellausgabe
 * geschrieben wird und nicht aus dem Ergebnis.
 */
class SystemHealth
{
    /** Ab wann eine wartende Aufgabe verdaechtig ist. */
    public const STALE_JOB_MINUTES = 10;

    /** Ab wann eine laufende Plangenerierung als haengend gilt. */
    public const STUCK_GENERATION_MINUTES = 20;

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
    public function queues(): array
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
    public function failedJobs(): array
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
    public function planHealth(): array
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
    public function integrations(): array
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

    /** @return array<string, mixed> */
    public function environment(): array
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


    /**
     * Derselbe Blick, aber auf einen Athleten.
     *
     * Was auf der Nutzerseite fehlte, war genau das, was bei der Suche nach
     * der Plan-Luecke gebraucht wurde: welche Neuberechnungen es gab und
     * warum, was im aktiven Plan tatsaechlich steht, und welche Einheiten
     * ohne Plan dastehen — die sind in der Datenbank, aber fuer den
     * Athleten unsichtbar, weil die Planseite nur den aktiven Plan laedt.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $plan = TrainingPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        $sessions = $plan
            ? TrainingSession::where('training_plan_id', $plan->id)->get(['id', 'planned_date', 'type', 'status'])
            : collect();

        $dates = $sessions->map(fn ($s) => $s->planned_date->format('Y-m-d'))->unique()->sort()->values();
        $holes = [];

        if ($dates->count() >= 2) {
            $have   = $dates->flip();
            $cursor = Carbon::parse($dates->first());
            $last   = Carbon::parse($dates->last());

            for (; $cursor->lte($last); $cursor->addDay()) {
                $key = $cursor->format('Y-m-d');
                if (! isset($have[$key])) {
                    $holes[] = $key;
                }
            }
        }

        $orphans = TrainingSession::where('user_id', $user->id)
            ->whereNull('training_plan_id')
            ->orderByDesc('planned_date')
            ->get(['id', 'planned_date', 'type', 'title', 'status'])
            ->map(fn ($s) => [
                'id'     => $s->id,
                'date'   => $s->planned_date->format('Y-m-d'),
                'type'   => $s->type,
                'title'  => $s->title,
                'status' => $s->status,
            ]);

        $revisions = \App\Models\PlanRevision::where('user_id', $user->id)
            ->latest('id')
            ->limit(12)
            ->get(['id', 'triggered_by', 'changes', 'corrections', 'created_at'])
            ->map(fn ($r) => [
                'id'          => $r->id,
                'trigger'     => $r->triggered_by,
                'label'       => \App\Models\PlanRevision::TRIGGER_LABELS[$r->triggered_by] ?? $r->triggered_by,
                'changes'     => count($r->changes ?? []),
                'corrections' => count($r->corrections ?? []),
                'at'          => $r->created_at?->toIso8601String(),
            ]);

        return [
            'plan' => $plan ? [
                'id'      => $plan->id,
                'created' => $plan->created_at?->toIso8601String(),
                'from'    => $dates->first(),
                'to'      => $dates->last(),
            ] : null,
            'counts' => [
                'planned'   => $sessions->where('status', 'planned')->count(),
                'completed' => $sessions->where('status', 'completed')->count(),
                'skipped'   => $sessions->where('status', 'skipped')->count(),
            ],
            'gaps'      => $holes,
            'orphans'   => $orphans->all(),
            'revisions' => $revisions->all(),
        ];
    }

    /**
     * Die Kurzfassung fuers Dashboard — genug, um zu entscheiden, ob man
     * hinsehen muss, und nicht mehr.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $plans = $this->planHealth();

        return [
            'stale_queues'    => collect($this->queues())->where('stale', true)->pluck('queue')->values()->all(),
            'failed'          => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'plans_with_gaps' => count($plans['gaps']),
            'orphans_planned' => $plans['orphans_planned'],
            'stuck'           => count($plans['stuck']),
        ];
    }
}
