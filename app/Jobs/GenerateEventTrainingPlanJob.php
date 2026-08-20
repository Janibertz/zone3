<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\TrainingPlanGenerator;
use App\Services\PlanContextBuilder;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * User-triggered training plan generation for a specific event.
 *
 * Runs in the queue (not the web request) so the single-threaded `php artisan serve`
 * web process is never blocked by the 100s+ OpenAI reasoning call. Generation state is
 * tracked on the event via `plan_generating` / `plan_error`; the frontend polls it.
 */
class GenerateEventTrainingPlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 1800; // matches the queue worker timeout — the AI call can take 100s+

    public function __construct(
        public readonly int $eventId,
        public readonly int $userId,
    ) {}

    public function handle(
        TrainingPlanGenerator $planner,
        WebPushService $webPush,
        PlanContextBuilder $contextBuilder,
    ): void
    {
        $user  = User::find($this->userId);
        $event = Event::where('id', $this->eventId)->where('user_id', $this->userId)->first();

        if (! $user || ! $event) {
            return;
        }

        if ($event->days_until < 0) {
            $event->update(['plan_generating' => false, 'plan_error' => 'Event liegt in der Vergangenheit.']);
            return;
        }

        // ── Kontext ──────────────────────────────────────────────────────────
        // Sammeln, Wochengeruest und Garmin-Zusammenfassung liegen im
        // PlanContextBuilder — die Neuberechnung nutzt denselben Weg.
        $existingPlan = TrainingPlan::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        $context  = $contextBuilder->build($user, $event, $existingPlan?->availability_overrides ?? []);
        $skeleton = $context->skeleton;

        $existingPlanIds   = TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->pluck('id');
        $preservedSessions = TrainingSession::whereIn('training_plan_id', $existingPlanIds)
            ->whereIn('status', ['skipped', 'completed'])
            ->get();

        // Der Stand vor der Erstellung. Beim ersten Plan leer, bei einer vom
        // Nutzer angestossenen Neuerstellung nicht — beides gehoert in den
        // Aenderungsverlauf.
        $sessionsBefore = TrainingSession::whereIn('training_plan_id', $existingPlanIds)
            ->where('status', 'planned')
            ->get();

        // ── Call AI ──────────────────────────────────────────────────────────
        $planner->withCoach($user->coach?->personality_prompt)->forUser($user->id);
        try {
            $aiSessions = $planner->generateEventTrainingPlan($context);
        } catch (\Throwable $e) {
            Log::error('GenerateEventTrainingPlanJob: OpenAI error', ['error' => $e->getMessage(), 'event_id' => $event->id]);
            $event->update(['plan_generating' => false, 'plan_error' => 'Der Coach konnte gerade keinen Plan erstellen. Bitte versuche es erneut.']);
            return;
        }

        if (! $aiSessions) {
            $event->update(['plan_generating' => false, 'plan_error' => 'Plan konnte nicht erstellt werden. Bitte versuche es erneut.']);
            return;
        }

        // ── Prüfen und reparieren ────────────────────────────────────────────
        // Bis hierher war der Prompt die einzige Durchsetzung. Der Validator
        // gleicht die Antwort gegen das Gerüst ab und protokolliert, was er
        // korrigieren musste.
        $eventDateStr = $event->event_date->format('Y-m-d');

        $checked    = app(\App\Services\TrainingPlanValidator::class)
            ->validate($aiSessions, $skeleton, $eventDateStr);
        $aiSessions = $checked['sessions'];

        if ($checked['report']) {
            Log::info('Plan validator corrected the AI output', [
                'event_id'    => $event->id,
                'corrections' => $checked['report'],
            ]);
        }

        try {
            // One-active-plan rule: deactivate all other plans
            TrainingPlan::where('user_id', $user->id)->update(['is_active' => false]);

            TrainingSession::whereIn('training_plan_id', $existingPlanIds)->where('status', 'planned')->delete();
            TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->delete();

            $plan = TrainingPlan::create([
                'user_id'  => $user->id,
                'event_id' => $event->id,
                'sessions' => $aiSessions,
                'context'  => [
                    'activities_used'    => count($context->recentActivities),
                    'wellbeing_entries'  => count($context->wellbeing),
                    'has_runner_profile' => $context->profile !== null,
                    'days_until_event'   => $event->days_until,
                    'used_garmin'        => $context->garminText !== null,
                    'weekly_pattern'     => collect($skeleton['weeks'])
                        ->map(fn ($w) => $w['planned'])->all(),
                    'corrections'        => $checked['report'],
                ],
            ]);

            DB::table('training_plans')
                ->where('id', $plan->id)
                ->update(['is_active' => true, 'needs_plan_update' => false]);

            app(\App\Services\PlanRevisionRecorder::class)->record(
                user:        $user,
                event:       $event,
                newPlan:     $plan,
                oldSessions: $sessionsBefore,
                newSessions: $aiSessions,
                corrections: $checked['report'] ?? [],
                triggeredBy: $sessionsBefore->isEmpty() ? 'initial' : 'user',
            );

            // Erhaltene Einheiten blockieren ihren Tag nur für Läufe. Vorher
            // fiel an einem Doppel-Tag die Krafteinheit mit weg, sobald der
            // Lauf von Strava importiert und damit erhalten war.
            $preservedRunDates = $preservedSessions
                ->reject(fn ($s) => in_array($s->type, ['strength', 'core', 'mobility'], true))
                ->pluck('planned_date')
                ->map(fn ($d) => $d->format('Y-m-d'))
                ->unique()
                ->flip()
                ->toArray();

            $preservedExtraDates = $preservedSessions
                ->filter(fn ($s) => in_array($s->type, ['strength', 'core', 'mobility'], true))
                ->pluck('planned_date')
                ->map(fn ($d) => $d->format('Y-m-d'))
                ->unique()
                ->flip()
                ->toArray();

            foreach ($preservedSessions as $session) {
                $session->update(['training_plan_id' => $plan->id]);
            }

            foreach ($aiSessions as $i => $s) {
                $date  = $s['date'] ?? '';
                $extra = in_array($s['type'] ?? '', ['strength', 'core', 'mobility'], true);

                // Gleiche Sorte an diesem Tag schon erhalten → nicht doppeln.
                if ($extra ? isset($preservedExtraDates[$date]) : isset($preservedRunDates[$date])) {
                    continue;
                }

                // Ruhetag neben einer erhaltenen Einheit wäre widersprüchlich.
                if (($s['type'] ?? '') === 'rest' && (isset($preservedRunDates[$date]) || isset($preservedExtraDates[$date]))) {
                    continue;
                }
                TrainingSession::create([
                    'user_id'          => $user->id,
                    'training_plan_id' => $plan->id,
                    'event_id'         => $event->id,
                    'planned_date'     => $s['date'],
                    'type'             => $s['type'] ?? 'easy_run',
                    'title'            => $s['title'] ?? '',
                    'description'      => $s['description'] ?? '',
                    'distance_km'      => ($s['distance_km'] ?? 0) ?: null,
                    'duration_min'     => ($s['duration_min'] ?? 0) ?: null,
                    'pace_target'      => ($s['pace_target'] === 'null' || empty($s['pace_target'])) ? null : $s['pace_target'],
                    'zone'             => $s['zone'] ?? null,
                    'intensity'        => $s['intensity'] ?? 'low',
                    'exercises'        => ! empty($s['exercises']) && is_array($s['exercises']) ? $s['exercises'] : null,
                    'status'           => 'planned',
                    'sort_order'       => $i,
                ]);
            }

            // ── Retroactively match Strava runs in plan window ───────────────
            $planDates = collect($aiSessions)->pluck('date');
            $planStart = $planDates->min();
            $planEnd   = $planDates->max();

            if ($planStart && $planEnd) {
                $recentRuns = $user->activities()
                    ->where('type', 'Run')
                    ->whereDate('start_date', '>=', $planStart)
                    ->whereDate('start_date', '<=', $planEnd)
                    ->get(\App\Models\Activity::SUMMARY_COLUMNS);

                foreach ($recentRuns as $run) {
                    $date         = $run->start_date->toDateString();
                    $plannedOnDate = TrainingSession::where('training_plan_id', $plan->id)
                        ->where('planned_date', $date)
                        ->where('status', 'planned')
                        ->orderBy('sort_order')
                        ->get();
                    // A day can hold two sessions (e.g. run + strength). Match the imported
                    // run to the RUNNING session, never a strength/core/mobility block. If the
                    // day is only strength (no run planned), leave it null so the run is added
                    // as a separate completed session below instead of overwriting the strength.
                    $sessionOnDate = $plannedOnDate->first(fn ($s) => ! in_array($s->type, ['rest', 'strength', 'core', 'mobility']))
                        ?? $plannedOnDate->first(fn ($s) => $s->type === 'rest');

                    $distKm = $run->distance > 0 ? round($run->distance / 1000, 2) : null;
                    $durMin = $run->moving_time > 0 ? (int) round($run->moving_time / 60) : null;
                    $pace   = $this->paceFromSpeed($run->average_speed);

                    if ($sessionOnDate && $sessionOnDate->type !== 'rest') {
                        $sessionOnDate->update([
                            'status'       => 'completed',
                            'activity_id'  => $run->id,
                            'distance_km'  => $distKm ?? $sessionOnDate->distance_km,
                            'duration_min' => $durMin ?? $sessionOnDate->duration_min,
                            'pace_target'  => $pace ?? $sessionOnDate->pace_target,
                        ]);
                        TrainingSession::where('user_id', $user->id)
                            ->where('activity_id', $run->id)
                            ->where('id', '!=', $sessionOnDate->id)
                            ->delete();
                    } elseif ($sessionOnDate && $sessionOnDate->type === 'rest') {
                        $sessionOnDate->delete();
                        TrainingSession::create([
                            'user_id'          => $user->id,
                            'training_plan_id' => $plan->id,
                            'event_id'         => $plan->event_id,
                            'activity_id'      => $run->id,
                            'planned_date'     => $date,
                            'type'             => 'easy_run',
                            'title'            => $run->name,
                            'distance_km'      => $distKm,
                            'duration_min'     => $durMin,
                            'pace_target'      => $pace,
                            'zone'             => null,
                            'intensity'        => 'medium',
                            'status'           => 'completed',
                            'sort_order'       => 0,
                        ]);
                    } else {
                        $existingSession = TrainingSession::where('user_id', $user->id)
                            ->where('activity_id', $run->id)
                            ->first();

                        if ($existingSession) {
                            $existingSession->update(['training_plan_id' => $plan->id, 'event_id' => $plan->event_id]);
                        } else {
                            TrainingSession::create([
                                'user_id'          => $user->id,
                                'training_plan_id' => $plan->id,
                                'event_id'         => $plan->event_id,
                                'activity_id'      => $run->id,
                                'planned_date'     => $date,
                                'type'             => 'easy_run',
                                'title'            => $run->name,
                                'distance_km'      => $distKm,
                                'duration_min'     => $durMin,
                                'pace_target'      => $pace,
                                'zone'             => null,
                                'intensity'        => 'medium',
                                'status'           => 'completed',
                                'sort_order'       => 999,
                            ]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('GenerateEventTrainingPlanJob: database error', ['error' => $e->getMessage(), 'event_id' => $event->id]);
            $event->update(['plan_generating' => false, 'plan_error' => 'Plan konnte nicht gespeichert werden. Bitte versuche es erneut.']);
            return;
        }

        // Clear cached coaching messages so the dashboard regenerates with the new plan context
        $user->runnerProfile?->update([
            'today_recommendation' => null,
            'recommendation_date'  => null,
            'daily_message'        => null,
            'daily_message_date'   => null,
        ]);

        // Success — clear generation state
        $event->update(['plan_generating' => false, 'plan_error' => null]);

        if ($user->push_notifications_enabled && $user->notify_plan_updated) {
            $coachName = $user->coach?->name ?? 'Dein Coach';
            $webPush->sendToUser(
                $user,
                "{$coachName} hat deinen Plan fertig 🗓️",
                "Dein Trainingsplan für {$event->name} ist bereit.",
                "/events/{$event->id}/plan"
            );
        }

        // Race prediction is skipped for Backyard (it uses deterministic yard readiness)
        if (! $event->isBackyard()) {
            GenerateRacePredictionJob::dispatch($plan->id)->delay(now()->addSeconds(5));
        }

        Log::info('GenerateEventTrainingPlanJob: plan generated', ['event_id' => $event->id, 'sessions' => count($aiSessions)]);
    }

    /** Reset generation state if the job fails hard (e.g. timeout). */
    public function failed(\Throwable $e): void
    {
        Event::where('id', $this->eventId)->update([
            'plan_generating' => false,
            'plan_error'      => 'Plan-Erstellung fehlgeschlagen. Bitte versuche es erneut.',
        ]);
    }

    private function formatPace(float $mps): string
    {
        if ($mps <= 0) return '—';
        $spk = 1000 / $mps;
        return (int)($spk / 60) . ':' . str_pad((int)($spk % 60), 2, '0', STR_PAD_LEFT);
    }

    private function paceFromSpeed(float $mps): ?string
    {
        if ($mps <= 0) return null;
        $secPerKm = 1000 / $mps;
        return sprintf('%d:%02d', (int)($secPerKm / 60), (int)($secPerKm % 60));
    }
}
