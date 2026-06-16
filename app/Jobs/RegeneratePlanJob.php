<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\OpenAIService;
use App\Services\TrainingLoadService;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\GenerateRacePredictionJob;

class RegeneratePlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 600; // reasoning model + OpenAI latency can exceed 2 min

    public function __construct(
        public readonly int  $userId,
        public readonly bool $userTriggered = false, // bypass debounce for user actions (skip/complete)
    ) {}

    public function handle(OpenAIService $openAI, WebPushService $webPush, TrainingLoadService $trainingLoadService): void
    {
        $user = User::find($this->userId);
        if (! $user) return;

        $plan = TrainingPlan::where('user_id', $this->userId)
            ->where('is_active', true)
            ->where('needs_plan_update', true)
            ->first();

        if (! $plan) return;

        // Debounce: skip if plan was regenerated less than 6 hours ago (batches Strava-sync triggers)
        // User-triggered actions (skip/complete) bypass this debounce for immediate response
        if (! $this->userTriggered && $plan->created_at->gt(now()->subHours(6))) {
            Log::info('RegeneratePlanJob: plan recently created, skipping', ['plan_id' => $plan->id]);
            $plan->update(['needs_plan_update' => false]);
            return;
        }

        $event = $plan->event;
        if (! $event || $event->days_until < 0) {
            $plan->update(['needs_plan_update' => false]);
            return;
        }

        // ── Gather context (mirrors TrainingPlanController::generate) ───────────
        $recentActivities = $user->activities()
            ->where('start_date', '>=', now()->subWeeks(4))
            ->orderByDesc('start_date')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'date'         => $a->start_date?->format('Y-m-d') ?? '',
                'name'         => $a->name,
                'distance_km'  => round($a->distance / 1000, 2),
                'duration_min' => (int) round($a->moving_time / 60),
                'pace'         => $a->average_speed > 0 ? $this->formatPace($a->average_speed) : null,
                'avg_hr'       => $a->average_heartrate ? (int) $a->average_heartrate : null,
            ])
            ->toArray();

        $wellbeingData = $user->wellbeingEntries()
            ->where('date', '>=', now()->subDays(14)->toDateString())
            ->orderByDesc('date')
            ->limit(14)
            ->get()
            ->map(fn ($w) => [
                'date'       => $w->date->format('Y-m-d'),
                'energy'     => $w->energy_level,
                'sleep'      => $w->sleep_quality,
                'soreness'   => $w->muscle_soreness,
                'stress'     => $w->stress_level,
                'is_sick'    => $w->is_sick,
                'is_injured' => $w->is_injured,
            ])
            ->toArray();

        $profileData = null;
        if ($rp = $user->runnerProfile) {
            $pace = $rp->threshold_speed;
            $mins = (int) $pace;
            $secs = (int) (($pace - $mins) * 60);
            $profileData = [
                'threshold_pace' => sprintf('%d:%02d', $mins, $secs),
                'threshold_hr'   => $rp->threshold_heart_rate,
                'max_hr'         => $rp->max_heart_rate,
            ];
        }

        $sessionRatings = TrainingSession::where('user_id', $user->id)
            ->whereNotNull('rating')
            ->where('status', 'completed')
            ->orderByDesc('planned_date')
            ->limit(30)
            ->get()
            ->map(fn ($s) => [
                'date'             => $s->planned_date->format('Y-m-d'),
                'type'             => $s->type,
                'distance_km'      => $s->distance_km,
                'rating'           => $s->rating,
                'effort_perceived' => $s->effort_perceived,
                'feeling_notes'    => $s->feeling_notes,
            ])
            ->toArray();

        $weeklyAvailability    = $user->runnerProfile?->weekly_availability ?? null;
        $availabilityOverrides = $plan->availability_overrides ?? [];

        $pastPlanResults = TrainingPlan::where('user_id', $user->id)
            ->whereNotNull('overall_rating')
            ->whereHas('event', fn ($q) => $q->where('event_date', '<', now()->toDateString()))
            ->with('event:id,name,race_distance,target_time_hours,target_time_minutes')
            ->latest()
            ->limit(3)
            ->get()
            ->map(fn ($p) => [
                'event_name'     => $p->event->name,
                'race_distance'  => $p->event->race_distance,
                'target_time'    => sprintf('%d:%02d', $p->event->target_time_hours, $p->event->target_time_minutes),
                'actual_time'    => $p->actual_time_hours !== null
                    ? sprintf('%d:%02d', $p->actual_time_hours, $p->actual_time_minutes)
                    : null,
                'overall_rating' => $p->overall_rating,
                'result_notes'   => $p->result_notes,
            ])
            ->toArray();

        $trainingLoad = $trainingLoadService->calculate($user->id);

        $planWindowEnd = now()->addDays(10)->format('Y-m-d');
        $otherEvents   = Event::where('user_id', $user->id)
            ->where('id', '!=', $event->id)
            ->where('event_date', '>=', now()->toDateString())
            ->where('event_date', '<=', $planWindowEnd)
            ->get()
            ->map(fn ($e) => [
                'date'     => $e->event_date->format('Y-m-d'),
                'name'     => $e->name,
                'distance' => $e->distance_label,
                'priority' => $e->priority,
            ])
            ->toArray();

        // ── Collect finalized sessions BEFORE calling AI ────────────────────────
        // These are skipped/completed sessions that must be preserved across plan regeneration.
        $oldPlanIds = TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->pluck('id');
        $preservedSessions = TrainingSession::whereIn('training_plan_id', $oldPlanIds)
            ->whereIn('status', ['skipped', 'completed'])
            ->get();

        // Future finalized sessions (not to be overwritten)
        $futureFinalized = $preservedSessions
            ->where('planned_date', '>=', now()->toDateString())
            ->map(fn ($s) => [
                'date'        => $s->planned_date->format('Y-m-d'),
                'type'        => $s->type,
                'status'      => $s->status,
                'skip_reason' => $s->skip_reason,
            ])
            ->values()
            ->toArray();

        // Past skipped sessions (last 7 days) — for recovery context (illness / injury / exhaustion)
        $pastSkipped = TrainingSession::where('user_id', $user->id)
            ->where('status', 'skipped')
            ->where('planned_date', '>=', now()->subDays(7)->toDateString())
            ->where('planned_date', '<', now()->toDateString())
            ->get()
            ->map(fn ($s) => [
                'date'        => $s->planned_date->format('Y-m-d'),
                'type'        => $s->type,
                'status'      => 'skipped',
                'skip_reason' => $s->skip_reason,
            ])
            ->values()
            ->toArray();

        $finalizedForAI = array_merge($pastSkipped, $futureFinalized);

        // ── Call OpenAI ─────────────────────────────────────────────────────────
        $openAI->withCoach($user->coach?->personality_prompt)->forUser($user->id);
        try {
            $aiSessions = $openAI->generateEventTrainingPlan(
                $event, $profileData, $recentActivities, $wellbeingData,
                $sessionRatings, $weeklyAvailability, $availabilityOverrides,
                $trainingLoad, $pastPlanResults, $otherEvents, $finalizedForAI
            );
        } catch (\Throwable $e) {
            Log::error('RegeneratePlanJob: OpenAI error', ['error' => $e->getMessage(), 'user_id' => $this->userId]);
            return;
        }

        if (! $aiSessions) {
            Log::warning('RegeneratePlanJob: no sessions returned', ['user_id' => $this->userId]);
            return;
        }

        $eventDateStr = $event->event_date->format('Y-m-d');
        $aiSessions   = array_values(array_filter($aiSessions, fn ($s) => ($s['date'] ?? '') <= $eventDateStr));

        // ── Replace plan in DB ──────────────────────────────────────────────────
        try {
            // $oldPlanIds already computed above (before AI call)
            TrainingSession::whereIn('training_plan_id', $oldPlanIds)->where('status', 'planned')->delete();
            TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->delete();

            $newPlan = TrainingPlan::create([
                'user_id'  => $user->id,
                'event_id' => $event->id,
                'sessions' => $aiSessions,
                'context'  => [
                    'activities_used'    => count($recentActivities),
                    'wellbeing_entries'  => count($wellbeingData),
                    'has_runner_profile' => (bool) $profileData,
                    'days_until_event'   => $event->days_until,
                    'auto_regenerated'   => true,
                ],
            ]);

            DB::table('training_plans')
                ->where('id', $newPlan->id)
                ->update(['is_active' => true, 'needs_plan_update' => false]);

            // Re-link preserved skipped/completed sessions to the new plan
            $preservedDates = $preservedSessions
                ->pluck('planned_date')
                ->map(fn ($d) => $d->format('Y-m-d'))
                ->unique()
                ->flip()
                ->toArray();
            foreach ($preservedSessions as $session) {
                $session->update(['training_plan_id' => $newPlan->id]);
            }

            // Create AI sessions — skip dates that already have a finalized session
            foreach ($aiSessions as $i => $s) {
                if (isset($preservedDates[$s['date'] ?? ''])) {
                    continue;
                }
                TrainingSession::create([
                    'user_id'          => $user->id,
                    'training_plan_id' => $newPlan->id,
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
                    'status'           => 'planned',
                    'sort_order'       => $i,
                ]);
            }

            // ── Retroactive run matching ──────────────────────────────────────
            $planDates = collect($aiSessions)->pluck('date');
            $planStart = $planDates->min();
            $planEnd   = $planDates->max();

            if ($planStart && $planEnd) {
                $recentRuns = $user->activities()
                    ->where('type', 'Run')
                    ->whereDate('start_date', '>=', $planStart)
                    ->whereDate('start_date', '<=', $planEnd)
                    ->get();

                foreach ($recentRuns as $run) {
                    $date          = $run->start_date->toDateString();
                    $sessionOnDate = TrainingSession::where('training_plan_id', $newPlan->id)
                        ->where('planned_date', $date)
                        ->where('status', 'planned')
                        ->first();

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
                            'training_plan_id' => $newPlan->id,
                            'event_id'         => $newPlan->event_id,
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
                            $existingSession->update(['training_plan_id' => $newPlan->id, 'event_id' => $newPlan->event_id]);
                        } else {
                            TrainingSession::create([
                                'user_id'          => $user->id,
                                'training_plan_id' => $newPlan->id,
                                'event_id'         => $newPlan->event_id,
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
            Log::error('RegeneratePlanJob: database error', ['error' => $e->getMessage(), 'user_id' => $this->userId]);
            return;
        }

        // Clear cached coaching messages so the dashboard regenerates them with the new plan context
        $user->runnerProfile?->update([
            'today_recommendation' => null,
            'recommendation_date'  => null,
            'daily_message'        => null,
            'daily_message_date'   => null,
        ]);

        if ($user->push_notifications_enabled && $user->notify_plan_updated) {
            $coachName = $user->coach?->name ?? 'Dein Coach';
            $webPush->sendToUser(
                $user,
                "{$coachName} hat deinen Plan aktualisiert",
                "Dein Trainingsplan für {$event->name} wurde automatisch neu berechnet.",
                "/events/{$event->id}/plan"
            );
        }

        // Refresh race prediction in background
        GenerateRacePredictionJob::dispatch($newPlan->id)->delay(now()->addSeconds(10));

        Log::info('RegeneratePlanJob: plan regenerated', ['user_id' => $this->userId, 'event_id' => $event->id]);
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
