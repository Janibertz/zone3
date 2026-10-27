<?php

namespace App\Services;

use App\Models\Activity;
use Carbon\Carbon;

/**
 * Ist diese Aktivität schon da, nur aus der anderen Quelle?
 *
 * Zone3 nimmt Aktivitäten aus Strava oder aus Garmin, eine Quelle pro
 * Athlet. Trotzdem kann dieselbe Einheit über beide Wege kommen: beim
 * Wechsel der Quelle, oder weil Zwift zu beiden hochlädt. Dann soll die
 * zweite ID an die bestehende Zeile, nicht eine zweite Zeile entstehen —
 * sonst zählt der Lauf doppelt in Umfang, Belastung und Plan.
 *
 * Gesucht wird nur unter Zeilen, denen die ID dieser Quelle noch fehlt.
 * Eine Zeile mit einer anderen Strava-ID ist eine andere Strava-Aktivität,
 * egal wie ähnlich sie aussieht.
 */
class ActivityMatcher
{
    /** Start höchstens so weit auseinander. */
    public const START_TOLERANCE_SECONDS = 120;

    /** Distanz (ohne Distanz: Dauer) höchstens so weit auseinander. */
    public const AMOUNT_TOLERANCE = 0.05;

    /**
     * @param  array  $attributes  die Werte der neuen Aktivität (start_date, type, distance, moving_time)
     * @param  int|null  $otherId  die ID der anderen Quelle, wenn die neue Aktivität sie selbst nennt
     */
    public function findTwin(int $userId, string $source, array $attributes, ?int $otherId = null): ?Activity
    {
        $column      = Activity::ID_COLUMNS[$source];
        $otherColumn = Activity::ID_COLUMNS[$source === Activity::SOURCE_STRAVA ? Activity::SOURCE_GARMIN : Activity::SOURCE_STRAVA];

        $open = fn () => Activity::where('user_id', $userId)->whereNull($column);

        // Querverweis: eine von Garmin zu Strava hochgeladene Aktivität nennt
        // bei Strava ihre Garmin-ID. Dann braucht es keine Schätzung.
        if ($otherId) {
            $hit = $open()->where($otherColumn, $otherId)->first();
            if ($hit) {
                return $hit;
            }
        }

        if (empty($attributes['start_date'])) {
            return null;
        }

        $start  = Carbon::parse($attributes['start_date'])->utc();
        $family = Activity::sportFamily($attributes['type'] ?? null);

        $candidates = $open()
            ->whereBetween('start_date', [
                $start->copy()->subSeconds(self::START_TOLERANCE_SECONDS),
                $start->copy()->addSeconds(self::START_TOLERANCE_SECONDS),
            ])
            ->get();

        foreach ($candidates as $candidate) {
            if (Activity::sportFamily($candidate->type) !== $family) {
                continue;
            }

            if ($this->sameAmount($candidate, $attributes)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Gleiche Strecke, ohne GPS (Laufband, Kraft) gleiche Dauer. */
    private function sameAmount(Activity $candidate, array $attributes): bool
    {
        $distance = (float) ($attributes['distance'] ?? 0);

        if ($distance > 0 && $candidate->distance > 0) {
            return $this->close($distance, (float) $candidate->distance);
        }

        $moving = (float) ($attributes['moving_time'] ?? 0);

        return $moving > 0 && $candidate->moving_time > 0
            && $this->close($moving, (float) $candidate->moving_time);
    }

    private function close(float $a, float $b): bool
    {
        return abs($a - $b) <= max($a, $b) * self::AMOUNT_TOLERANCE;
    }
}
