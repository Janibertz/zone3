<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Services\OpenAIService;
use App\Services\TrainingLoadService;
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

        $sessions = $plan
            ? TrainingSession::where('training_plan_id', $plan->id)
                ->orderBy('planned_date')
                ->get()
                ->map(fn ($s) => $this->formatSession($s))
                ->values()
                ->toArray()
            : [];

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
                'target_time_formatted' => $event->target_time_formatted,
                'days_until'            => $event->days_until,
            ],
            'plan' => $plan ? [
                'id'                  => $plan->id,
                'is_active'           => (bool) $plan->is_active,
                'generated_at'        => $plan->created_at->format('d.m.Y H:i'),
                'context'             => $plan->context,
                'needs_plan_update'   => $plan->needs_plan_update,
                'actual_time_hours'   => $plan->actual_time_hours,
                'actual_time_minutes' => $plan->actual_time_minutes,
                'overall_rating'      => $plan->overall_rating,
                'result_notes'        => $plan->result_notes,
            ] : null,
            'sessions'    => $sessions,
            'isPastEvent' => $isPastEvent,
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

    public function generate(Event $event, OpenAIService $openAI, WebPushService $webPush, TrainingLoadService $trainingLoadService)
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

        $user = Auth::user();

        // ── Gather context data ──────────────────────────────────────────────
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

        // ── Session ratings from previous plans (for AI learning) ───────────
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
                'rating'           => $s->rating,           // 1–5
                'effort_perceived' => $s->effort_perceived, // RPE 1–10
                'feeling_notes'    => $s->feeling_notes,
            ])
            ->toArray();

        // ── Availability ─────────────────────────────────────────────────────
        $weeklyAvailability  = $user->runnerProfile?->weekly_availability ?? null;

        // Get existing plan's overrides if regenerating
        $existingPlan = TrainingPlan::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->latest()
            ->first();
        $availabilityOverrides = $existingPlan?->availability_overrides ?? [];

        // ── Past race results (rated plans) ──────────────────────────────────
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

        // ── Training load metrics (CTL / ATL / TSB) ──────────────────────────
        $trainingLoad = $trainingLoadService->calculate($user->id);

        // ── Other events in the plan window (race days → no training) ────────
        $planEnd = now()->addDays(10)->format('Y-m-d');
        $otherEvents = Event::where('user_id', $user->id)
            ->where('id', '!=', $event->id)
            ->where('event_date', '>=', now()->toDateString())
            ->where('event_date', '<=', $planEnd)
            ->get()
            ->map(fn ($e) => [
                'date'     => $e->event_date->format('Y-m-d'),
                'name'     => $e->name,
                'distance' => $e->distance_label,
                'priority' => $e->priority,
            ])
            ->toArray();

        // ── Call AI ──────────────────────────────────────────────────────────
        try {
            $aiSessions = $openAI->generateEventTrainingPlan($event, $profileData, $recentActivities, $wellbeingData, $sessionRatings, $weeklyAvailability, $availabilityOverrides, $trainingLoad, $pastPlanResults, $otherEvents);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'OpenAI-Fehler: ' . $e->getMessage()], 500);
        }

        if (! $aiSessions) {
            return response()->json(['error' => 'Plan konnte nicht erstellt werden. Bitte versuche es erneut.'], 500);
        }

        // Strip any sessions the AI placed after the event date
        $eventDateStr = $event->event_date->format('Y-m-d');
        $aiSessions = array_values(array_filter($aiSessions, fn ($s) => ($s['date'] ?? '') <= $eventDateStr));

        try {
            // ── One-active-plan rule: deactivate all other plans ─────────────
            TrainingPlan::where('user_id', $user->id)->update(['is_active' => false]);

            // ── Delete planned sessions of old plans for this event ──────────
            $oldPlanIds = TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->pluck('id');
            TrainingSession::whereIn('training_plan_id', $oldPlanIds)->where('status', 'planned')->delete();
            TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->delete();

            // ── Create new plan (without needs_plan_update — rely on DB default) ──
            $plan = TrainingPlan::create([
                'user_id'  => $user->id,
                'event_id' => $event->id,
                'sessions' => $aiSessions,
                'context'  => [
                    'activities_used'    => count($recentActivities),
                    'wellbeing_entries'  => count($wellbeingData),
                    'has_runner_profile' => (bool) $profileData,
                    'days_until_event'   => $event->days_until,
                ],
            ]);

            // Set is_active via direct update (not in $fillable)
            \Illuminate\Support\Facades\DB::table('training_plans')
                ->where('id', $plan->id)
                ->update(['is_active' => true, 'needs_plan_update' => false]);

            // ── Create individual TrainingSession records ────────────────────
            foreach ($aiSessions as $i => $s) {
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
                    ->get();

                foreach ($recentRuns as $run) {
                    $date = $run->start_date->toDateString();

                    // Find any planned session on this date (including rest days)
                    $sessionOnDate = TrainingSession::where('training_plan_id', $plan->id)
                        ->where('planned_date', $date)
                        ->where('status', 'planned')
                        ->first();

                    $distKm  = $run->distance > 0 ? round($run->distance / 1000, 2) : null;
                    $durMin  = $run->moving_time > 0 ? (int) round($run->moving_time / 60) : null;
                    $pace    = $this->paceFromSpeed($run->average_speed);

                    if ($sessionOnDate && $sessionOnDate->type !== 'rest') {
                        // Replace planned session data with actual Strava data
                        $sessionOnDate->update([
                            'status'      => 'completed',
                            'activity_id' => $run->id,
                            'distance_km' => $distKm ?? $sessionOnDate->distance_km,
                            'duration_min'=> $durMin ?? $sessionOnDate->duration_min,
                            'pace_target' => $pace ?? $sessionOnDate->pace_target,
                        ]);
                    } elseif ($sessionOnDate && $sessionOnDate->type === 'rest') {
                        // User ran on a planned rest day — remove rest day, add actual run
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
                        // No session on this date — re-link existing or create unplanned entry
                        $existingSession = TrainingSession::where('user_id', $user->id)
                            ->where('activity_id', $run->id)
                            ->first();

                        if ($existingSession) {
                            // Re-link existing session to new plan (preserves rating/notes)
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
            return response()->json(['error' => 'Datenbankfehler: ' . $e->getMessage()], 500);
        }

        // ── Reload and return sessions ───────────────────────────────────────
        $plan->refresh();
        $sessions = TrainingSession::where('training_plan_id', $plan->id)
            ->orderBy('planned_date')
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($s) => $this->formatSession($s))
            ->values()
            ->toArray();

        // Notify user if they have push + plan-update notifications enabled
        $user = Auth::user();
        if ($user->push_notifications_enabled && $user->notify_plan_updated) {
            $webPush->sendToUser(
                $user,
                'KI-Plan aktualisiert 🧠',
                "Dein Trainingsplan für {$event->name} wurde neu berechnet.",
                "/events/{$event->id}/plan"
            );
        }

        return response()->json([
            'plan' => [
                'id'                => $plan->id,
                'is_active'         => true,
                'generated_at'      => $plan->created_at->format('d.m.Y H:i'),
                'context'           => $plan->context,
                'needs_plan_update' => false,
            ],
            'sessions' => $sessions,
        ]);
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

    private function formatSession(TrainingSession $s): array
    {
        return [
            'id'          => $s->id,
            'planned_date' => $s->planned_date->format('Y-m-d'),
            'type'         => $s->type,
            'title'        => $s->title,
            'description'  => $s->description,
            'distance_km'  => $s->distance_km,
            'duration_min' => $s->duration_min,
            'pace_target'  => $s->pace_target,
            'zone'         => $s->zone,
            'intensity'    => $s->intensity,
            'status'           => $s->status,
            'skip_reason'      => $s->skip_reason,
            'sort_order'       => $s->sort_order,
            'activity_id'      => $s->activity_id,
            'rating'           => $s->rating,
            'effort_perceived' => $s->effort_perceived,
            'feeling_notes'    => $s->feeling_notes,
        ];
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
