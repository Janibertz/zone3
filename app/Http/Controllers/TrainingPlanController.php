<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateEventTrainingPlanJob;
use App\Jobs\GenerateRacePredictionJob;
use App\Models\Activity;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Services\AI\CoachingTextService;
use App\Services\RacePredictionService;
use App\Services\TrainingLoadService;
use App\Services\WeatherService;
use App\Services\WebPushService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class TrainingPlanController extends Controller
{
    public function show(Event $event)
    {
        abort_if($event->user_id !== Auth::id(), 403);

        $isPastEvent = $event->days_until < 0;

        $plan = TrainingPlan::where('event_id', $event->id)
            ->where('user_id', Auth::id())
            ->latest()
            ->first();

        // Auto-deactivate if event has passed and plan is still marked active
        if ($isPastEvent && $plan && $plan->is_active) {
            $plan->update(['is_active' => false]);
        }

        // Einen haengengebliebenen Lauf hier aufraeumen. Die Seite ist der
        // einzige Ort, an dem jemand den Zustand ueberhaupt bemerkt — und
        // ohne das Aufraeumen bliebe die Schaltfläche wirkungslos, weil
        // generate() bei gesetztem Schalter nichts tut.
        if ($event->hasStalePlanGeneration()) {
            $event->update([
                'plan_generating'    => false,
                'plan_generating_at' => null,
                'plan_error'         => $plan
                    ? null
                    : 'Die letzte Plan-Erstellung wurde unterbrochen. Bitte starte sie erneut.',
            ]);
        }

        // Die Rennzeit aus Strava uebernehmen.
        //
        // Der Lauf kommt ohnehin ueber den Webhook herein, und die
        // Renn-Analyse ordnet ihm laengst die richtige Aktivitaet zu — nur
        // das Ergebnisfeld daneben blieb leer, bis jemand die Zeit von Hand
        // abtippte. Wer das vergass, hatte das Rennen zwar gelaufen, aber
        // fuer die naechste Planung war es nie stattgefunden: pastPlanResults
        // liest genau dieses Feld.
        $resultSource = null;
        if ($isPastEvent && $plan) {
            $resultSource = $this->adoptRaceResult($event, $plan);
        }

        $sessions = $plan
            ? TrainingSession::where('training_plan_id', $plan->id)
                ->with('activity:id,laps')
                ->orderBy('planned_date')
                ->get()
                ->map(fn ($s) => $this->formatSession($s))
                ->values()
                ->toArray()
            : [];

        // Keep AI prediction text fresh via async job (only the text, not the numbers).
        // Skipped for Backyard Ultra — it uses the deterministic yard-readiness estimate.
        if ($plan && $plan->is_active && ! $isPastEvent && ! $event->isBackyard()) {
            $stale = $plan->prediction_updated_at === null
                || now()->diffInHours($plan->prediction_updated_at) > 12;
            if ($stale) {
                GenerateRacePredictionJob::dispatch($plan->id)->delay(now()->addSeconds(3));
            }
        }

        // Calculate prediction from threshold pace (same formula as Dashboard).
        // Backyard Ultra uses a separate yard-readiness estimate instead.
        $profile    = RunnerProfile::where('user_id', Auth::id())->first();
        $threshPred = (! $event->isBackyard() && $profile?->threshold_speed)
            ? app(RacePredictionService::class)->fromThreshold($profile->threshold_speed, $event->race_distance, $event->distance_km)
            : null;

        // Delta vs. target time (positive = faster than goal)
        $targetSec = ($event->target_time_hours * 3600) + ($event->target_time_minutes * 60);
        $deltaSec  = null;
        if ($threshPred && $targetSec > 0) {
            $deltaSec = $targetSec - $threshPred['total_sec'];
        }

        // Backyard-specific: yard readiness + lap rhythm table
        $backyard = $event->isBackyard()
            ? [
                'readiness' => $this->calcBackyardReadiness(Auth::id(), $event),
                'rhythm'    => $this->yardRhythmTable(),
            ]
            : null;

        return Inertia::render('Events/Plan', [
            'event' => [
                'id'                    => $event->id,
                'name'                  => $event->name,
                'event_date'            => $event->event_date->format('Y-m-d'),
                'race_distance'         => $event->race_distance,
                'distance_label'        => $event->distance_label,
                'priority'              => $event->priority,
                'target_time_hours'     => $event->target_time_hours,
                'target_time_minutes'   => $event->target_time_minutes,
                'target_yards'          => $event->target_yards,
                'target_distance_km'    => $event->target_distance_km,
                'target_time_formatted' => $event->target_time_formatted,
                'days_until'            => $event->days_until,
                'plan_generating'       => $event->isGeneratingPlan(),
                'plan_error'            => $event->plan_error,
            ],
            'backyard' => $backyard,
            // True when the race is beyond the rolling window, so the plan only covers
            // the next stretch and will be extended automatically over time.
            'planIsRolling' => (bool) ($plan && ! $isPastEvent && $event->days_until >= Event::PLAN_HORIZON_DAYS),
            'plan' => $plan ? [
                'id'                          => $plan->id,
                'is_active'                   => (bool) $plan->is_active,
                'generated_at'                => $plan->created_at->format('d.m.Y H:i'),
                'context'                     => $plan->context,
                'needs_plan_update'           => $plan->needs_plan_update,
                'actual_time_hours'           => $plan->actual_time_hours,
                'actual_time_minutes'         => $plan->actual_time_minutes,
                'overall_rating'              => $plan->overall_rating,
                'result_notes'                => $plan->result_notes,
                // Threshold-pace based prediction (matches Dashboard values exactly)
                'predicted_finish_time'       => $threshPred ? $threshPred['time'] : $plan->predicted_finish_time,
                'predicted_pace'              => $threshPred ? $threshPred['pace'] : $plan->predicted_pace,
                'prediction_target_delta_sec' => $deltaSec ?? $plan->prediction_target_delta_sec,
                'prediction_text'             => $plan->prediction_text,
                'prediction_source'           => $threshPred ? 'threshold' : 'riegel',
            ] : null,
            'sessions'    => $sessions,
            'isPastEvent' => $isPastEvent,

            // Gesetzt, wenn die Zeit gerade aus Strava uebernommen wurde —
            // damit im Ergebnisfeld steht, woher die Zahl kommt.
            'resultSource' => $resultSource,

            // Der Änderungsverlauf. Er hängt am Event, nicht am Plan — jede
            // Neuberechnung löscht den Plan, der Verlauf überlebt sie.
            'revisions'   => \App\Models\PlanRevision::where('user_id', Auth::id())
                ->where('event_id', $event->id)
                // Nach der ID als zweitem Kriterium: zwei Revisionen in
                // derselben Sekunde standen sonst in beliebiger Reihenfolge.
                ->latest()
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn ($r) => [
                    'id'          => $r->id,
                    'at'          => $r->created_at->format('d.m.Y H:i'),
                    'kind'        => $r->triggered_by,
                    'label'       => $r->triggerLabel(),
                    'changes'     => $r->changes ?? [],
                    'corrections' => $r->corrections ?? [],
                ])
                ->values(),
        ]);
    }

    /**
     * Save the athlete's actual race result and plan rating for a completed event.
     */
    public function saveResult(Event $event, \Illuminate\Http\Request $request)
    {
        abort_if($event->user_id !== Auth::id(), 403);

        if ($event->days_until >= 0) {
            return response()->json(['error' => 'Das Event liegt noch nicht in der Vergangenheit.'], 422);
        }

        $validated = $request->validate([
            'actual_time_hours'   => 'nullable|integer|min:0|max:23',
            'actual_time_minutes' => 'nullable|integer|min:0|max:59',
            'overall_rating'      => 'nullable|integer|min:1|max:5',
            'result_notes'        => 'nullable|string|max:1000',
        ]);

        $plan = TrainingPlan::where('event_id', $event->id)
            ->where('user_id', Auth::id())
            ->latest()
            ->first();

        if (! $plan) {
            return response()->json(['error' => 'Kein Plan gefunden.'], 404);
        }

        $plan->update($validated);

        return response()->json([
            'success' => true,
            'plan'    => [
                'actual_time_hours'   => $plan->actual_time_hours,
                'actual_time_minutes' => $plan->actual_time_minutes,
                'overall_rating'      => $plan->overall_rating,
                'result_notes'        => $plan->result_notes,
            ],
        ]);
    }

    /**
     * Cancel (deactivate) the active plan for an event.
     */
    public function cancel(Event $event)
    {
        abort_if($event->user_id !== Auth::id(), 403);

        TrainingPlan::where('user_id', Auth::id())
            ->where('event_id', $event->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        return response()->json(['success' => true]);
    }

    public function generate(Event $event)
    {
        abort_if($event->user_id !== Auth::id(), 403);

        if ($event->days_until < 0) {
            return response()->json(['error' => 'Dieses Event liegt in der Vergangenheit. Bitte erstelle ein neues Event für einen neuen Plan.'], 422);
        }

        // Block if a different event already has an active plan
        $otherActivePlan = TrainingPlan::where('user_id', Auth::id())
            ->where('is_active', true)
            ->where('event_id', '!=', $event->id)
            ->first();

        if ($otherActivePlan) {
            return response()->json([
                'error' => 'Es gibt bereits einen aktiven Plan für ein anderes Event. Bitte brich diesen zuerst ab.',
            ], 422);
        }

        // Already running for this event → don't queue a second job.
        // Ein haengengebliebener Schalter zaehlt nicht als "laeuft": sonst
        // liesse sich die Planerstellung nie wieder anstossen.
        if ($event->isGeneratingPlan()) {
            return response()->json(['generating' => true]);
        }

        // Dispatch the heavy AI generation to the queue so the single-threaded
        // web process is never blocked by the 100s+ OpenAI reasoning call.
        $event->update([
            'plan_generating'    => true,
            'plan_generating_at' => now(),
            'plan_error'         => null,
        ]);
        GenerateEventTrainingPlanJob::dispatch($event->id, Auth::id());

        return response()->json(['generating' => true]);
    }

    /**
     * Poll the async plan-generation status for an event (frontend polling).
     */
    public function generateStatus(Event $event)
    {
        abort_if($event->user_id !== Auth::id(), 403);

        if ($event->isGeneratingPlan()) {
            return response()->json(['status' => 'generating']);
        }

        // Sonst wartet die Oberflaeche endlos auf ein Ende, das nicht kommt.
        if ($event->hasStalePlanGeneration()) {
            $event->update([
                'plan_generating'    => false,
                'plan_generating_at' => null,
                'plan_error'         => 'Die Plan-Erstellung wurde unterbrochen. Bitte starte sie erneut.',
            ]);

            return response()->json(['status' => 'failed', 'error' => $event->plan_error]);
        }

        if ($event->plan_error) {
            return response()->json(['status' => 'failed', 'error' => $event->plan_error]);
        }
        return response()->json(['status' => 'ready']);
    }

    /**
     * Store or update a per-date availability override for the active plan.
     */
    public function updateAvailabilityOverride(Event $event, \Illuminate\Http\Request $request)
    {
        abort_if($event->user_id !== Auth::id(), 403);

        $request->validate([
            'date'         => 'required|date_format:Y-m-d',
            'available'    => 'required|boolean',
            'duration_min' => 'nullable|integer|min:0|max:300',
        ]);

        $plan = TrainingPlan::where('event_id', $event->id)
            ->where('user_id', Auth::id())
            ->where('is_active', true)
            ->firstOrFail();

        $overrides = $plan->availability_overrides ?? [];
        $overrides[$request->date] = [
            'available'    => $request->available,
            'duration_min' => $request->duration_min ?? 0,
        ];
        $plan->update(['availability_overrides' => $overrides, 'needs_plan_update' => true]);

        return response()->json(['success' => true, 'overrides' => $overrides]);
    }

    /**
     * Race-day pacing strategy: deterministic km splits for the target time
     * plus a cached AI strategy text (generated on first view during race week).
     */
    public function raceStrategy(Event $event, CoachingTextService $text, WeatherService $weather)
    {
        abort_if($event->user_id !== Auth::id(), 403);

        $distanceKm = $this->raceDistanceKm($event);
        $targetSec  = ($event->target_time_hours * 3600) + ($event->target_time_minutes * 60);

        // Backyard Ultra has no target finish time — it uses the yard-rhythm card instead.
        if ($event->isBackyard() || ! $distanceKm || $targetSec <= 0) {
            return response()->json(['available' => false]);
        }

        $pacing = $this->pacingSplits($distanceKm, $targetSec);

        $plan = TrainingPlan::where('event_id', $event->id)
            ->where('user_id', Auth::id())
            ->latest()
            ->first();

        $strategyText = $plan?->race_strategy_text;
        if ($plan && ! $strategyText) {
            $user        = Auth::user();
            $weatherData = ($event->days_until >= 0 && $event->days_until <= 7) ? $weather->forUser($user) : null;

            $text->withCoach($user->coach?->personality_prompt)->forUser($user->id);
            $strategyText = $text->generateRaceStrategy([
                'name'                  => $event->name,
                'race_distance'         => $event->distance_label,
                'target_time_formatted' => $event->target_time_formatted ?? 'nicht gesetzt',
                'days_until'            => $event->days_until,
            ], $pacing['pace'], $weatherData);

            if ($strategyText) {
                $plan->update(['race_strategy_text' => $strategyText]);
            }
        }

        return response()->json([
            'available'     => true,
            'pace'          => $pacing['pace'],
            'splits'        => $pacing['splits'],
            'strategy_text' => $strategyText,
        ]);
    }

    /**
     * Post-race analysis: matches the Strava race run and returns a cached AI
     * analysis (generated on first view after the race).
     */
    public function raceAnalysis(Event $event, CoachingTextService $text)
    {
        abort_if($event->user_id !== Auth::id(), 403);

        if ($event->days_until >= 0) {
            return response()->json(['found' => false, 'reason' => 'not_past']);
        }

        $plan = TrainingPlan::where('event_id', $event->id)
            ->where('user_id', Auth::id())
            ->latest()
            ->first();
        if (! $plan) {
            return response()->json(['found' => false]);
        }

        $activity = $this->findRaceActivity($event, $this->raceDistanceKm($event));
        if (! $activity) {
            return response()->json(['found' => false]);
        }

        $analysisText = $plan->race_analysis_text;

        // Regenerate when not yet generated or the matched activity changed.
        if (! $analysisText || $plan->race_analysis_activity_id !== $activity->id) {
            $user = Auth::user();
            $text->withCoach($user->coach?->personality_prompt)->forUser($user->id);
            $analysisText = $text->generateRaceAnalysis([
                'name'          => $event->name,
                'race_distance' => $event->distance_label,
            ], $event->target_time_formatted, [
                'time'        => $this->secToClock((int) $activity->moving_time),
                'pace'        => $this->paceFromSpeed($activity->average_speed) ?? '—',
                'distance_km' => round($activity->distance / 1000, 2),
                'splits_text' => $this->lapsSplitsText($activity),
            ]);

            if ($analysisText) {
                $plan->update([
                    'race_analysis_text'        => $analysisText,
                    'race_analysis_activity_id' => $activity->id,
                ]);
            }
        }

        return response()->json([
            'found'            => true,
            'analysis_text'    => $analysisText,
            'actual_time'      => $this->secToClock((int) $activity->moving_time),
            'race_activity_id' => $activity->id,
        ]);
    }

    /** Canonical race distance in km, or the custom distance. */
    private function raceDistanceKm(Event $event): ?float
    {
        return match ($event->race_distance) {
            '5km'           => 5.0,
            '10km'          => 10.0,
            'half_marathon' => 21.0975,
            'marathon'      => 42.195,
            'backyard_ultra'=> $event->target_distance_km,
            default         => $event->distance_km ?: null,
        };
    }

    /**
     * Even-pace km splits for a target time.
     * @return array{pace: string, splits: array<int, array{label: string, cumulative_time: string, is_finish?: bool}>}
     */
    private function pacingSplits(float $distanceKm, int $targetSec): array
    {
        $avgPaceSec = $targetSec / $distanceKm;
        $step       = $distanceKm <= 12 ? 1 : 5;

        $splits = [];
        for ($km = $step; $km < $distanceKm - 0.05; $km += $step) {
            $splits[] = [
                'label'           => $km . ' km',
                'cumulative_time' => $this->secToClock((int) round($km * $avgPaceSec)),
            ];
        }
        $splits[] = [
            'label'           => $this->kmLabel($distanceKm),
            'cumulative_time' => $this->secToClock($targetSec),
            'is_finish'       => true,
        ];

        return ['pace' => $this->secToPace((int) round($avgPaceSec)), 'splits' => $splits];
    }

    /** Most plausible race run: a Run within ±1 day of the event, closest to the race distance. */
    /**
     * Traegt die gelaufene Zeit ein, falls sie noch fehlt.
     *
     * Nur wenn das Feld leer ist — eine von Hand eingetragene Zeit ist die
     * offizielle und wird nie ueberschrieben. Gemessen wird elapsed_time,
     * nicht moving_time: bei einem Rennen zaehlt die Uhr am
     * Verpflegungsstand weiter.
     *
     * @return array{time: string, activity: string}|null  Nur beim Uebernehmen gesetzt.
     */
    private function adoptRaceResult(Event $event, TrainingPlan $plan): ?array
    {
        if ($plan->actual_time_hours !== null || $plan->actual_time_minutes !== null) {
            return null;
        }

        // Ein Backyard besteht aus vielen einzelnen Yards, oft als einzelne
        // Aktivitaeten aufgezeichnet. Die Dauer des laengsten davon waere
        // nicht das Ergebnis, sondern eine Runde.
        if ($event->isBackyard()) {
            return null;
        }

        $activity = $this->findRaceActivity($event, $this->raceDistanceKm($event));
        if (! $activity || $activity->elapsed_time <= 0) {
            return null;
        }

        $seconds = (int) $activity->elapsed_time;

        // Das Feld fasst nur Stunden bis 23 — laenger heisst hier ohnehin,
        // dass die Zuordnung nicht stimmt.
        if ($seconds >= 24 * 3600) {
            return null;
        }

        $plan->update([
            'actual_time_hours'   => intdiv($seconds, 3600),
            'actual_time_minutes' => intdiv($seconds % 3600, 60),
        ]);

        return [
            'time'     => $this->secToClock($seconds),
            'activity' => $activity->name,
        ];
    }

    private function findRaceActivity(Event $event, ?float $distanceKm): ?Activity
    {
        $candidates = Activity::where('user_id', Auth::id())
            ->where('type', 'Run')
            ->whereDate('start_date', '>=', $event->event_date->copy()->subDay()->toDateString())
            ->whereDate('start_date', '<=', $event->event_date->copy()->addDay()->toDateString())
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }
        if (! $distanceKm) {
            return $candidates->sortByDesc('distance')->first();
        }
        return $candidates->sortBy(fn ($a) => abs(($a->distance / 1000) - $distanceKm))->first();
    }

    /** Build a per-lap split summary from an activity's stored laps. */
    private function lapsSplitsText(Activity $activity): ?string
    {
        $laps = $activity->laps;
        if (! is_array($laps) || count($laps) < 2) {
            return null;
        }

        $lines = [];
        foreach ($laps as $i => $lap) {
            $distKm = isset($lap['distance']) ? round($lap['distance'] / 1000, 2) : null;
            $sec    = (int) ($lap['moving_time'] ?? $lap['elapsed_time'] ?? 0);
            if (! $distKm || $distKm <= 0 || $sec <= 0) {
                continue;
            }
            $paceSec = (int) round($sec / $distKm);
            $lines[] = 'Runde ' . ($i + 1) . ": {$distKm} km in " . $this->secToClock($sec)
                . ' (' . $this->secToPace($paceSec) . '/km)';
            if (count($lines) >= 30) {
                break;
            }
        }

        return $lines ? implode("\n", $lines) : null;
    }

    private function secToClock(int $sec): string
    {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    private function secToPace(int $sec): string
    {
        return sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
    }

    private function kmLabel(float $km): string
    {
        $str = rtrim(rtrim(number_format(round($km, 3), 2, '.', ''), '0'), '.');
        return $str . ' km';
    }

    private function formatSession(TrainingSession $s): array
    {
        return [
            'id'          => $s->id,
            'planned_date' => $s->planned_date->format('Y-m-d'),
            'type'         => $s->type,
            // Die Sportart, damit die Oberflaeche "Schwimmen" zeigen kann
            // und nicht nur "Alternativtraining".
            'sport_label'  => $s->isRun() ? null : $s->sportLabel(),
            'title'        => $s->title,
            'description'  => $s->description,
            'distance_km'  => $s->distance_km,
            'duration_min' => $s->duration_min,
            'pace_target'  => $s->pace_target,
            'zone'         => $s->zone,
            'intensity'    => $s->intensity,
            'exercises'    => $s->exercises,
            // Der Plan, bevor Strava die Ist-Werte darueber geschrieben hat.
            'planned_snapshot' => $s->planned_snapshot,
            'was_unplanned'    => (bool) $s->was_unplanned,
            // Vom Athleten selbst gesetzt — ueberlebt jede Neuberechnung.
            'pinned'           => $s->pinned_at !== null,
            'status'           => $s->status,
            'skip_reason'      => $s->skip_reason,
            'sort_order'       => $s->sort_order,
            'activity_id'      => $s->activity_id,
            'rating'           => $s->rating,
            'effort_perceived' => $s->effort_perceived,
            'feeling_notes'    => $s->feeling_notes,
            'coach_review'     => $s->coach_review,
            'review_question'  => $s->review_question,
            'review_options'   => $s->review_options,
            'review_feedback'  => $s->review_feedback,
            // Real splits from the matched Strava activity (completed sessions only).
            'laps'             => $this->sessionLaps($s),
        ];
    }

    /**
     * Raw per-lap splits from the matched Strava activity, for completed sessions only.
     * Returns null when there are no usable laps (so the UI hides the splits block).
     */
    private function sessionLaps(TrainingSession $s): ?array
    {
        if ($s->status !== 'completed' || ! $s->relationLoaded('activity')) {
            return null;
        }
        $laps = $s->activity?->laps;
        return (is_array($laps) && count($laps) > 1) ? array_values($laps) : null;
    }

    private function formatPace(float $mps): string
    {
        if ($mps <= 0) return '—';
        $spk = 1000 / $mps;
        return (int)($spk / 60) . ':' . str_pad(((int) $spk) % 60, 2, '0', STR_PAD_LEFT);
    }

    private function paceFromSpeed(float $mps): ?string
    {
        if ($mps <= 0) return null;
        $secPerKm = 1000 / $mps;
        return sprintf('%d:%02d', (int)($secPerKm / 60), ((int) $secPerKm) % 60);
    }

    /**
     * Heuristic yard-readiness estimate for a Backyard Ultra.
     * Blends single-run endurance (longest run) with weekly volume — no AI call.
     * A backyard runner can typically exceed their longest continuous run because
     * of the rest banked between loops, so the long-run term gets a modest multiplier.
     */
    private function calcBackyardReadiness(int $userId, Event $event): array
    {
        $lap    = Event::BACKYARD_LAP_KM;
        $target = (int) $event->target_yards;

        $runs = Activity::where('user_id', $userId)
            ->where('type', 'Run')
            ->whereDate('start_date', '>=', now()->subDays(84)->toDateString())
            ->get(['distance', 'start_date']);

        if ($runs->isEmpty()) {
            return [
                'has_data'       => false,
                'target_yards'   => $target,
                'estimated_yards'=> null,
                'advice'         => 'Noch keine Laufdaten der letzten 12 Wochen — verbinde Strava oder absolviere erste Läufe für eine Einschätzung.',
            ];
        }

        $longestRunKm = round($runs->max('distance') / 1000, 1);

        // Peak weekly volume (km) over the last 12 weeks
        $weekly = [];
        foreach ($runs as $r) {
            $wk = $r->start_date->format('o-W');
            $weekly[$wk] = ($weekly[$wk] ?? 0) + $r->distance / 1000;
        }
        $peakWeeklyKm = round(max($weekly), 1);

        // Blend: long-run endurance (with rest bonus) + a share of weekly volume
        $estYards = (int) round(($longestRunKm / $lap) * 1.5 + ($peakWeeklyKm / $lap) * 0.25);
        $estYards = max(1, $estYards);

        // Limiting factor relative to the goal
        $longrunYards = ($longestRunKm / $lap) * 1.5;
        $volumeYards  = $peakWeeklyKm / $lap;
        if ($longrunYards < $target * 0.6) {
            $limiter = 'longrun';
        } elseif ($volumeYards < $target * 0.8) {
            $limiter = 'volume';
        } else {
            $limiter = 'ready';
        }

        // Advice tone based on estimate vs. goal
        if ($estYards >= $target) {
            $advice = 'Dein Training trägt das Ziel — jetzt zählen Pacing-Disziplin (langsam genug für Pause) und Verpflegung.';
        } elseif ($estYards >= $target * 0.7) {
            $advice = match ($limiter) {
                'longrun' => 'Auf gutem Weg — verlängere deine Longruns und plane Back-to-Back-Wochenenden.',
                'volume'  => 'Auf gutem Weg — steigere schrittweise dein Wochenvolumen.',
                default   => 'Auf gutem Weg — halte das Volumen und übe den Stundenrhythmus.',
            };
        } else {
            $advice = 'Noch eine Lücke zum Ziel — Schwerpunkt auf aerobes Volumen und Time-on-Feet, Tempo ist zweitrangig.';
        }

        return [
            'has_data'        => true,
            'target_yards'    => $target,
            'estimated_yards' => $estYards,
            'range_low'       => max(1, $estYards - 2),
            'range_high'      => $estYards + 2,
            'longest_run_km'  => $longestRunKm,
            'peak_weekly_km'  => $peakWeeklyKm,
            'limiter'         => $limiter,
            'advice'          => $advice,
        ];
    }

    /**
     * Lap-rhythm table for a Backyard Ultra: for a set of candidate paces, show how
     * long one 6.706 km loop takes and how much rest remains within the hour.
     * Helps the athlete pick a sustainable pace — every minute saved is banked rest.
     */
    private function yardRhythmTable(): array
    {
        $lap  = Event::BACKYARD_LAP_KM;
        $rows = [];
        foreach ([360, 390, 420, 450, 480] as $paceSec) { // 6:00 … 8:00 min/km
            $lapSec = (int) round($paceSec * $lap);
            if ($lapSec >= 3600) {
                continue; // too slow to make the hourly cutoff
            }
            $restSec = 3600 - $lapSec;
            $rows[] = [
                'pace'        => $this->secToPace($paceSec),
                'lap_time'    => $this->secToClock($lapSec),
                'rest_min'    => (int) round($restSec / 60),
            ];
        }
        return $rows;
    }
}
