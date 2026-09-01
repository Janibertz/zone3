<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Event;
use App\Services\PaceFormat;

class PredictFinishTimeService
{
    /**
     * Predict finish time for the given event based on the athlete's recent Strava runs.
     *
     * Uses Riegel's formula: T2 = T1 × (D2/D1)^1.06
     * Picks the best performance (lowest predicted finish time) from the last 90 days.
     *
     * Returns null if fewer than 2 qualifying runs are found.
     *
     * @return array{
     *   predicted_finish_time: string,
     *   predicted_pace: string,
     *   prediction_trend: string,
     *   prediction_target_delta_sec: int|null,
     *   prediction_run_count: int,
     * }|null
     */
    public function predict(int $userId, Event $event): ?array
    {
        $targetKm = $this->targetDistanceKm($event);

        $runs = Activity::where('user_id', $userId)
            ->where('type', 'Run')
            ->where('start_date', '>=', now()->subDays(90))
            ->where('distance', '>=', 2500)   // at least 2.5 km to be meaningful
            ->where('moving_time', '>', 0)
            ->where('average_speed', '>', 0)
            ->orderByDesc('start_date')
            ->limit(30)
            ->get(Activity::SUMMARY_COLUMNS);

        if ($runs->count() < 2) {
            return null;
        }

        // Best performance = lowest Riegel-projected finish time
        $best = null;
        foreach ($runs as $run) {
            $distKm  = $run->distance / 1000;
            $timeSec = $run->moving_time;
            $projected = $timeSec * pow($targetKm / $distKm, 1.06);

            if ($best === null || $projected < $best['projected_seconds']) {
                $best = [
                    'projected_seconds' => $projected,
                    'source_km'         => $distKm,
                ];
            }
        }

        $predictedSec = (int) round($best['projected_seconds']);
        $timeStr      = $this->formatSeconds($predictedSec);
        $paceSecPerKm = $predictedSec / $targetKm;
        $paceStr      = PaceFormat::fromSeconds($paceSecPerKm);

        // Gap to target time
        $targetSec = null;
        $deltaSec  = null;
        if ($event->target_time_hours !== null || $event->target_time_minutes !== null) {
            $targetSec = (int)(($event->target_time_hours ?? 0) * 3600
                + ($event->target_time_minutes ?? 0) * 60);
            if ($targetSec > 0) {
                $deltaSec = $targetSec - $predictedSec; // positive = currently faster than goal
            }
        }

        // Trend: compare oldest-half avg vs newest-half avg predicted time
        $trend = $this->computeTrend($runs->values(), $targetKm);

        return [
            'predicted_finish_time'     => $timeStr,
            'predicted_pace'            => $paceStr,
            'prediction_trend'          => $trend,
            'prediction_target_delta_sec' => $deltaSec,
            'prediction_run_count'      => $runs->count(),
        ];
    }

    private function computeTrend(\Illuminate\Support\Collection $runs, float $targetKm): string
    {
        $count = $runs->count();
        if ($count < 4) {
            return 'stable';
        }

        $half    = (int) floor($count / 2);
        $newer   = $runs->take($half);        // most recent first
        $older   = $runs->slice($half, $half);

        $avgNewer = $this->avgProjected($newer, $targetKm);
        $avgOlder = $this->avgProjected($older, $targetKm);

        if ($avgNewer === null || $avgOlder === null) {
            return 'stable';
        }

        $diff = $avgOlder - $avgNewer; // positive = newer runs are faster → improving
        if ($diff > 90)  return 'improving';
        if ($diff < -90) return 'declining';
        return 'stable';
    }

    private function avgProjected(\Illuminate\Support\Collection $runs, float $targetKm): ?float
    {
        if ($runs->isEmpty()) return null;
        $total = 0;
        foreach ($runs as $run) {
            $distKm  = $run->distance / 1000;
            $timeSec = $run->moving_time;
            $total  += $timeSec * pow($targetKm / $distKm, 1.06);
        }
        return $total / $runs->count();
    }

    private function targetDistanceKm(Event $event): float
    {
        return match ($event->race_distance) {
            '5km'           => 5.0,
            '10km'          => 10.0,
            'half_marathon' => 21.0975,
            'marathon'      => 42.195,
            default         => $event->distance_km ?? 10.0,
        };
    }

    private function formatSeconds(int $seconds): string
    {
        $h = (int)($seconds / 3600);
        $m = (int)(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }
}
