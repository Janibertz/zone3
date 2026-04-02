<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Goal;
use Illuminate\Support\Carbon;

class ProgressService
{
    /**
     * Calculate progress for a specific goal based on user activities.
     *
     * @param Goal $goal
     * @return array{
     *     completed_distance_km: float,
     *     target_distance_km: float,
     *     progress_percentage: float,
     *     activities_count: int,
     *     activities_total_time_hours: float,
     *     target_time: array{hours: int, minutes: int},
     *     status: string,
     *     days_remaining: int,
     *     pace_recommendation: string
     * }
     */
    public function calculateProgress(Goal $goal): array
    {
        $now = Carbon::now();
        $startDate = Carbon::parse($goal->start_date);
        $endDate = Carbon::parse($goal->end_date);

        // Get activities for this goal within the date range
        $activities = Activity::where('user_id', $goal->user_id)
            ->whereBetween('start_date', [$startDate, $endDate])
            ->get();

        // Calculate metrics
        $completedDistanceKm = $activities->sum('distance') / 1000; // Convert from meters
        $targetDistanceKm = $goal->target_value;
        $progressPercentage = $targetDistanceKm > 0 ? ($completedDistanceKm / $targetDistanceKm) * 100 : 0;
        $progressPercentage = min($progressPercentage, 100); // Cap at 100%

        // Time calculations
        $totalMovingTimeSeconds = $activities->sum('moving_time');
        $totalMovingTimeHours = $totalMovingTimeSeconds / 3600;

        // Days remaining
        $daysRemaining = max(0, $now->diffInDays($endDate, false));
        $totalDays = $startDate->diffInDays($endDate);
        $daysElapsed = $now->diffInDays($startDate);

        // Pace recommendation
        $remainingDistance = max(0, $targetDistanceKm - $completedDistanceKm);
        $paceRecommendation = $this->calculatePaceRecommendation(
            $remainingDistance,
            $daysRemaining,
            $activities->count()
        );

        // Status determination
        $status = $this->determineStatus(
            $progressPercentage,
            $daysRemaining,
            $daysElapsed,
            $totalDays
        );

        return [
            'completed_distance_km' => round($completedDistanceKm, 2),
            'target_distance_km' => $targetDistanceKm,
            'progress_percentage' => round($progressPercentage, 1),
            'activities_count' => $activities->count(),
            'activities_total_time_hours' => round($totalMovingTimeHours, 2),
            'target_time' => [
                'hours' => $goal->target_time_hours,
                'minutes' => $goal->target_time_minutes,
            ],
            'status' => $status,
            'days_remaining' => $daysRemaining,
            'pace_recommendation' => $paceRecommendation,
            'start_date' => $goal->start_date->toDateString(),
            'end_date' => $goal->end_date->toDateString(),
        ];
    }

    /**
     * Get progress for all user goals.
     *
     * @param \App\Models\User $user
     * @return array
     */
    public function getAllGoalsProgress($user): array
    {
        return $user->goals()
            ->where('active', true)
            ->get()
            ->map(fn($goal) => [
                'goal_id' => $goal->id,
                'goal_name' => $goal->name,
                'progress' => $this->calculateProgress($goal),
            ])
            ->toArray();
    }

    /**
     * Calculate pace recommendation based on remaining distance and time.
     *
     * @param float $remainingDistanceKm
     * @param int $daysRemaining
     * @param int $activitiesCount
     * @return string
     */
    protected function calculatePaceRecommendation(
        float $remainingDistanceKm,
        int $daysRemaining,
        int $activitiesCount
    ): string {
        if ($daysRemaining <= 0) {
            return $remainingDistanceKm <= 0
                ? '✓ Ziel erreicht!'
                : '⚠ Ziel verpasst! ' . round($remainingDistanceKm, 1) . ' km fehlen.';
        }

        if ($remainingDistanceKm <= 0) {
            return '✓ Ziel erreicht!';
        }

        $weeklyPaceNeeded = ($remainingDistanceKm / $daysRemaining) * 7;
        $activitiesPerWeek = $activitiesCount > 0 ? ($activitiesCount / (ceil($daysRemaining / 7))) : 0;

        if ($weeklyPaceNeeded > 0) {
            return sprintf(
                '📍 Du brauchst ca. %.1f km/Woche (aktuell: ~%.1f km/Woche)',
                $weeklyPaceNeeded,
                $weeklyPaceNeeded * 0.8 // Rough estimate based on activities
            );
        }

        return 'Laufe weiter!';
    }

    /**
     * Determine goal status based on progress and time.
     *
     * @param float $progressPercentage
     * @param int $daysRemaining
     * @param int $daysElapsed
     * @param int $totalDays
     * @return string
     */
    protected function determineStatus(
        float $progressPercentage,
        int $daysRemaining,
        int $daysElapsed,
        int $totalDays
    ): string {
        if ($daysRemaining <= 0) {
            return $progressPercentage >= 100 ? 'completed' : 'missed';
        }

        $expectedProgress = $totalDays > 0 ? ($daysElapsed / $totalDays) * 100 : 0;

        if ($progressPercentage >= $expectedProgress * 1.2) {
            return 'on_track_ahead';
        } elseif ($progressPercentage >= $expectedProgress * 0.8) {
            return 'on_track';
        } else {
            return 'behind';
        }
    }

    /**
     * Generate training suggestions based on user activities and goals.
     *
     * @param \App\Models\User $user
     * @return array
     */
    public function generateTrainingSuggestions($user): array
    {
        $suggestions = [];
        $allGoalsProgress = $this->getAllGoalsProgress($user);

        foreach ($allGoalsProgress as $goalProgress) {
            $progress = $goalProgress['progress'];
            $status = $progress['status'];

            // Suggestion 1: If behind schedule
            if ($status === 'behind') {
                $suggestions[] = [
                    'type' => 'behind_schedule',
                    'title' => '⚠ Du hinkelst hinterher!',
                    'message' => sprintf(
                        'Dein Ziel "%s" ist bei %.1f%% Progress. Du solltest noch %s km trainieren.',
                        $goalProgress['goal_name'],
                        $progress['progress_percentage'],
                        $progress['completed_distance_km']
                    ),
                    'goal_id' => $goalProgress['goal_id'],
                    'priority' => 'high',
                ];
            }

            // Suggestion 2: If on track
            if ($status === 'on_track') {
                $suggestions[] = [
                    'type' => 'on_track',
                    'title' => '🎯 Du bist auf Kurs!',
                    'message' => sprintf(
                        'Super! Du erreichst %.1f%% deines Ziels "%s". %s',
                        $progress['progress_percentage'],
                        $goalProgress['goal_name'],
                        $progress['pace_recommendation']
                    ),
                    'goal_id' => $goalProgress['goal_id'],
                    'priority' => 'normal',
                ];
            }

            // Suggestion 3: If ahead
            if ($status === 'on_track_ahead') {
                $suggestions[] = [
                    'type' => 'ahead_of_schedule',
                    'title' => '🚀 Du bist voraus!',
                    'message' => sprintf(
                        'Fantastisch! Du hast bereits %.1f%% deines Ziels "%s" erreicht. Du könntest noch weiter gehen!',
                        $progress['progress_percentage'],
                        $goalProgress['goal_name']
                    ),
                    'goal_id' => $goalProgress['goal_id'],
                    'priority' => 'low',
                ];
            }

            // Suggestion 4: Rest recommendation
            $recentActivitiesCount = Activity::where('user_id', $user->id)
                ->where('start_date', '>=', Carbon::now()->subDays(7))
                ->count();

            if ($recentActivitiesCount >= 6) {
                $suggestions[] = [
                    'type' => 'rest_day_recommended',
                    'title' => '😴 Zeit zum Ausruhen?',
                    'message' => 'Du hast in der letzten Woche ' . $recentActivitiesCount . ' Trainingseinheiten absolviert. Ein Ruhetag könnte gut für die Regeneration sein.',
                    'goal_id' => null,
                    'priority' => 'normal',
                ];
            }
        }

        // Sort by priority
        usort($suggestions, fn($a, $b) => 
            ($a['priority'] === 'high' ? 0 : ($a['priority'] === 'normal' ? 1 : 2)) <=>
            ($b['priority'] === 'high' ? 0 : ($b['priority'] === 'normal' ? 1 : 2))
        );

        return array_slice($suggestions, 0, 3); // Return top 3 suggestions
    }
}
