<?php

namespace App\Services;

use App\Models\Goal;
use Illuminate\Support\Carbon;

class TrainingPlanService
{
    /**
     * Generate a simple weekly plan based on a goal and historical activities.
     *
     * @param Goal $goal
     * @param array<int, array> $activities
     * @return array<string, mixed>
     */
    public function generatePlan(Goal $goal, array $activities): array
    {
        $now = Carbon::now();
        $oneMonthAgo = $now->copy()->subDays(28);

        $recentActivities = array_filter($activities, function ($activity) use ($oneMonthAgo) {
            if (! isset($activity['start_date'])) {
                return false;
            }

            $date = Carbon::parse($activity['start_date']);

            return $date->greaterThanOrEqualTo($oneMonthAgo);
        });

        $totalDistanceKm = array_reduce($recentActivities, function ($carry, $activity) {
            $distance = $activity['distance'] ?? 0; // in meters
            return $carry + ($distance / 1000);
        }, 0.0);

        $weeks = 4;
        $avgWeeklyDistance = $weeks > 0 ? $totalDistanceKm / $weeks : 0;

        $goalTarget = (float) $goal->target_value;
        $goalUnit = $goal->unit ?: 'km';

        $recommendedWeekly = $goalTarget;
        if ($goal->type === 'distance' && strtolower($goalUnit) === 'km') {
            // If the goal is monthly, assume target_value is monthly.
            // Recommend weekly target as a quarter of monthly goal.
            $recommendedWeekly = $goalTarget / 4;
        }

        $sessionsPerWeek = 3;
        $baseSession = round($recommendedWeekly / $sessionsPerWeek, 1);

        $weeksPlan = [];
        for ($week = 1; $week <= 4; $week++) {
            $sessions = [];

            for ($i = 1; $i <= $sessionsPerWeek; $i++) {
                $sessions[] = [
                    'name' => "Workout #{$i}",
                    'description' => "Easy training run",
                    'distance_km' => $baseSession,
                ];
            }

            $weeksPlan[] = [
                'week' => $week,
                'sessions' => $sessions,
            ];
        }

        return [
            'current_average_weekly_distance_km' => round($avgWeeklyDistance, 1),
            'recommended_weekly_distance_km' => round($recommendedWeekly, 1),
            'plan' => $weeksPlan,
        ];
    }
}
