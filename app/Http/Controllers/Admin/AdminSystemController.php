<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Services\SystemHealth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Systemstatus — läuft die Maschine, nicht: läuft das Geschäft.
 *
 * Der Admin-Bereich beantwortete bisher nur die zweite Frage: Nutzer,
 * Aktivitäten, KI-Kosten, Coach-Verteilung. Zweimal in einer Woche stand
 * hier aber die erste an, und beide Male gab es keine Antwort — der
 * ausbleibende Strava-Import und zwei Tage, die aus einem Plan
 * verschwanden.
 *
 * Die Zahlen selbst kommen aus `SystemHealth`; dieselben stehen als
 * Warnzeile auf dem Dashboard. Hier bleiben nur die Eingriffe.
 */
class AdminSystemController extends Controller
{
    public function __construct(private readonly SystemHealth $health) {}

    public function index(): Response
    {
        return Inertia::render('Admin/System/Index', [
            'queues'       => $this->health->queues(),
            'failedJobs'   => $this->health->failedJobs(),
            'planHealth'   => $this->health->planHealth(),
            'integrations' => $this->health->integrations(),
            'environment'  => $this->health->environment(),
            // Dasselbe Urteil wie auf der Uebersicht — nicht ein zweites.
            'summary'      => $this->health->summary(),
        ]);
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
