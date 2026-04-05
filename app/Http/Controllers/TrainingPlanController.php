<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\TrainingPlan;
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
                'id'           => $plan->id,
                'sessions'     => $plan->sessions,
                'generated_at' => $plan->created_at->format('d.m.Y H:i'),
                'context'      => $plan->context,
            ] : null,
        ]);
    }

    public function generate(Event $event, OpenAIService $openAI)
    {
        abort_if($event->user_id !== Auth::id(), 403);

        $user = Auth::user();

        // Recent activities (last 4 weeks)
        $recentActivities = $user->activities()
            ->where('start_date', '>=', now()->subWeeks(4))
            ->orderByDesc('start_date')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'date'        => $a->start_date?->format('Y-m-d') ?? '',
                'name'        => $a->name,
                'distance_km' => round($a->distance / 1000, 2),
                'duration_min'=> (int) round($a->moving_time / 60),
                'pace'        => $a->average_speed > 0 ? $this->formatPace($a->average_speed) : null,
                'avg_hr'      => $a->average_heartrate ? (int) $a->average_heartrate : null,
            ])
            ->toArray();

        // Wellbeing (last 14 days)
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

        // Runner profile
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

        $sessions = $openAI->generateEventTrainingPlan($event, $profileData, $recentActivities, $wellbeingData);

        if (! $sessions) {
            return response()->json(['error' => 'Plan konnte nicht erstellt werden. Bitte versuche es erneut.'], 500);
        }

        // Replace any existing plan for this event
        TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->delete();

        $plan = TrainingPlan::create([
            'user_id'  => $user->id,
            'event_id' => $event->id,
            'sessions' => $sessions,
            'context'  => [
                'activities_used'     => count($recentActivities),
                'wellbeing_entries'   => count($wellbeingData),
                'has_runner_profile'  => (bool) $profileData,
                'days_until_event'    => $event->days_until,
            ],
        ]);

        return response()->json([
            'plan' => [
                'id'           => $plan->id,
                'sessions'     => $plan->sessions,
                'generated_at' => $plan->created_at->format('d.m.Y H:i'),
                'context'      => $plan->context,
            ],
        ]);
    }

    private function formatPace(float $metersPerSecond): string
    {
        if ($metersPerSecond <= 0) return '—';
        $secondsPerKm = 1000 / $metersPerSecond;
        $minutes = (int) ($secondsPerKm / 60);
        $seconds = (int) ($secondsPerKm % 60);
        return "{$minutes}:{$seconds}";
    }
}
