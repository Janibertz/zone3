<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\BestEffort;

/**
 * Imports Strava "best efforts" (fastest 1k/5k/10k/half/marathon section
 * within a run) into the best_efforts table and exposes read helpers for
 * the personal-records UI.
 */
class BestEffortService
{
    /** Tracked distances → canonical distance in meters. */
    public const DISTANCES = [
        '1k'       => 1000,
        '5k'       => 5000,
        '10k'      => 10000,
        'half'     => 21097,
        'marathon' => 42195,
    ];

    /** Display labels per distance key. */
    public const LABELS = [
        '1k'       => '1 km',
        '5k'       => '5 km',
        '10k'      => '10 km',
        'half'     => 'Halbmarathon',
        'marathon' => 'Marathon',
    ];

    /** Tolerance (meters) when matching a Strava effort to a canonical distance. */
    private const TOLERANCE_M = 5;

    /**
     * Extract best efforts from a Strava detail payload, upsert them, and
     * mark the activity as processed.
     *
     * @return string[] distance keys for which this activity is a *new* all-time
     *                  #1 (beating a pre-existing record). Callers decide whether
     *                  to celebrate — backfill of old runs should ignore this.
     */
    public function syncFromActivityData(Activity $activity, array $detail): array
    {
        // Always mark as processed so the backfill never re-fetches this run,
        // even when it has no best efforts (too short / no GPS data).
        $activity->forceFill(['best_efforts_synced_at' => now()])->save();

        $efforts = $detail['best_efforts'] ?? [];
        if (! is_array($efforts) || empty($efforts)) {
            return [];
        }

        $newRecords = [];

        foreach (self::DISTANCES as $key => $distanceM) {
            $effort = $this->matchEffort($efforts, $distanceM);
            if (! $effort) {
                continue;
            }

            $elapsed = (int) $effort['elapsed_time'];
            if ($elapsed <= 0) {
                continue;
            }

            // Is this the first time we store this distance for this activity?
            $existing = BestEffort::where('activity_id', $activity->id)
                ->where('distance_key', $key)
                ->first();

            // Previous all-time best for this user/distance, excluding this activity.
            $prevBest = BestEffort::where('user_id', $activity->user_id)
                ->where('distance_key', $key)
                ->where('activity_id', '!=', $activity->id)
                ->min('elapsed_time');

            BestEffort::updateOrCreate(
                ['activity_id' => $activity->id, 'distance_key' => $key],
                [
                    'user_id'      => $activity->user_id,
                    'distance_m'   => $distanceM,
                    'elapsed_time' => $elapsed,
                    'achieved_at'  => $activity->start_date,
                ],
            );

            // New record only when this is genuinely new data (not a re-sync)
            // and it beats a pre-existing record.
            if ($existing === null && $prevBest !== null && $elapsed < $prevBest) {
                $newRecords[] = $key;
            }
        }

        return $newRecords;
    }

    /**
     * Top 3 fastest efforts per tracked distance for a user, display-ready.
     *
     * @return array<int, array{key:string,label:string,distance_m:int,entries:array}>
     */
    public function topThree(int $userId): array
    {
        $rows = BestEffort::where('user_id', $userId)
            ->whereIn('distance_key', array_keys(self::DISTANCES))
            ->with('activity:id,name')
            ->orderBy('distance_key')
            ->orderBy('elapsed_time')
            ->get();

        $grouped = $rows->groupBy('distance_key');

        $result = [];
        foreach (self::DISTANCES as $key => $distanceM) {
            $entries = ($grouped[$key] ?? collect())
                ->take(3)
                ->values()
                ->map(fn (BestEffort $be, int $i) => [
                    'rank'           => $i + 1,
                    'time_formatted' => $this->formatDuration($be->elapsed_time),
                    'pace'           => $this->pacePerKm($be->elapsed_time, $be->distance_m),
                    'date'           => $be->achieved_at->format('d.m.Y'),
                    'activity_id'    => $be->activity_id,
                    'activity_name'  => $be->activity?->name,
                ])
                ->toArray();

            $result[] = [
                'key'        => $key,
                'label'      => self::LABELS[$key],
                'distance_m' => $distanceM,
                'entries'    => $entries,
            ];
        }

        return $result;
    }

    /**
     * Per-distance time series of every effort (oldest → newest) for the chart.
     *
     * @return array<string, array<int, array{date:string,elapsed_time:int,time_formatted:string}>>
     */
    public function history(int $userId): array
    {
        $rows = BestEffort::where('user_id', $userId)
            ->whereIn('distance_key', array_keys(self::DISTANCES))
            ->orderBy('achieved_at')
            ->get(['distance_key', 'achieved_at', 'elapsed_time']);

        $history = [];
        foreach (array_keys(self::DISTANCES) as $key) {
            $history[$key] = [];
        }

        foreach ($rows as $be) {
            $history[$be->distance_key][] = [
                'date'           => $be->achieved_at->format('Y-m-d'),
                'elapsed_time'   => $be->elapsed_time,
                'time_formatted' => $this->formatDuration($be->elapsed_time),
            ];
        }

        return $history;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Find the Strava effort whose distance matches the canonical distance. */
    private function matchEffort(array $efforts, int $distanceM): ?array
    {
        foreach ($efforts as $effort) {
            if (! isset($effort['distance'], $effort['elapsed_time'])) {
                continue;
            }
            if (abs((int) round($effort['distance']) - $distanceM) <= self::TOLERANCE_M) {
                return $effort;
            }
        }
        return null;
    }

    /** Seconds → "M:SS" or "H:MM:SS". */
    private function formatDuration(int $sec): string
    {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }

    /** Pace per km as "M:SS". */
    private function pacePerKm(int $sec, int $distanceM): string
    {
        if ($distanceM <= 0) {
            return '–';
        }
        $secPerKm = (int) round($sec * 1000 / $distanceM);

        return sprintf('%d:%02d', intdiv($secPerKm, 60), $secPerKm % 60);
    }
}
