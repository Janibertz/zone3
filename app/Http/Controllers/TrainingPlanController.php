<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Services\OpenAIService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TrainingPlanController extends Controller
{
    public function show(Event $event)
    {
        abort_if($event->user_id !== Auth::id(), 403);

        $plan = TrainingPlan::where('event_id', $event->id)
            ->where('user_id', Auth::id())
            ->latest()
            ->first();

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
                'id'                => $plan->id,
                'is_active'         => $plan->is_active,
                'generated_at'      => $plan->created_at->format('d.m.Y H:i'),
                'context'           => $plan->context,
                'needs_plan_update' => $plan->needs_plan_update,
            ] : null,
            'sessions' => $sessions,
        ]);
    }

    public function generate(Event $event, OpenAIService $openAI)
    {
        abort_if($event->user_id !== Auth::id(), 403);

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

        // ── Call AI ──────────────────────────────────────────────────────────
        $aiSessions = $openAI->generateEventTrainingPlan($event, $profileData, $recentActivities, $wellbeingData);

        if (! $aiSessions) {
            return response()->json(['error' => 'Plan konnte nicht erstellt werden. Bitte versuche es erneut.'], 500);
        }

        // ── One-active-plan rule: deactivate all other plans ─────────────────
        TrainingPlan::where('user_id', $user->id)->update(['is_active' => false]);

        // ── Delete planned sessions of old plans for this event ──────────────
        $oldPlanIds = TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->pluck('id');
        TrainingSession::whereIn('training_plan_id', $oldPlanIds)->where('status', 'planned')->delete();
        TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->delete();

        // ── Create new plan ──────────────────────────────────────────────────
        $plan = TrainingPlan::create([
            'user_id'           => $user->id,
            'event_id'          => $event->id,
            'is_active'         => true,
            'needs_plan_update' => false,
            'sessions'          => $aiSessions,
            'context'           => [
                'activities_used'    => count($recentActivities),
                'wellbeing_entries'  => count($wellbeingData),
                'has_runner_profile' => (bool) $profileData,
                'days_until_event'   => $event->days_until,
            ],
        ]);

        // ── Create individual TrainingSession records ────────────────────────
        $sessions = [];
        foreach ($aiSessions as $i => $s) {
            $ts = TrainingSession::create([
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
            $sessions[] = $this->formatSession($ts);
        }

        // ── Retroactively match Strava runs to the new plan sessions ────────
        // Covers all runs in the plan window, regardless of session type (rest days included).
        $planDates = collect($aiSessions)->pluck('date');
        $planStart = $planDates->min();
        $planEnd   = $planDates->max();

        $recentRuns = $user->activities()
            ->where('type', 'Run')
            ->whereDate('start_date', '>=', $planStart)
            ->whereDate('start_date', '<=', $planEnd)
            ->get();

        foreach ($recentRuns as $run) {
            $date = $run->start_date->toDateString();

            // Find non-rest planned session on this date
            $matchedSession = TrainingSession::where('training_plan_id', $plan->id)
                ->where('planned_date', $date)
                ->where('type', '!=', 'rest')
                ->where('status', 'planned')
                ->first();

            if ($matchedSession) {
                $matchedSession->update(['status' => 'completed', 'activity_id' => $run->id]);
            } else {
                // Plan has a rest day or no session — add as unplanned completed entry
                $alreadyLinked = TrainingSession::where('training_plan_id', $plan->id)
                    ->where('activity_id', $run->id)
                    ->exists();

                if (! $alreadyLinked) {
                    TrainingSession::create([
                        'user_id'          => $user->id,
                        'training_plan_id' => $plan->id,
                        'event_id'         => $plan->event_id,
                        'activity_id'      => $run->id,
                        'planned_date'     => $date,
                        'type'             => 'easy_run',
                        'title'            => 'Ungeplante Einheit',
                        'description'      => $run->name,
                        'distance_km'      => $run->distance > 0 ? round($run->distance / 1000, 2) : null,
                        'duration_min'     => $run->moving_time > 0 ? (int) round($run->moving_time / 60) : null,
                        'pace_target'      => $this->paceFromSpeed($run->average_speed),
                        'zone'             => null,
                        'intensity'        => 'medium',
                        'status'           => 'completed',
                        'sort_order'       => 999,
                    ]);
                }
            }
        }

        // Reload sessions so the response includes completed/unplanned ones
        $sessions = TrainingSession::where('training_plan_id', $plan->id)
            ->orderBy('planned_date')
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($s) => $this->formatSession($s))
            ->values()
            ->toArray();

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
            'status'       => $s->status,
            'skip_reason'  => $s->skip_reason,
            'sort_order'   => $s->sort_order,
            'activity_id'  => $s->activity_id,
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
