<?php

namespace App\Services;

use App\Models\BestEffort;
use App\Models\RunnerProfile;
use App\Services\PaceFormat;

/**
 * Woher der VDOT eines Athleten kommt — die einzige Stelle, die das sagt.
 *
 * Zwei Quellen, und es gilt die höhere (Entscheidung vom 25.10.2026):
 *
 * - **Schwellenpace** (`threshold_speed`, KI-berechnet aus den letzten
 *   Läufen). Trägt durch Wochen ohne schnellen Lauf.
 * - **Strava-Bestzeiten** der letzten sechs Wochen über 5 km, 10 km,
 *   Halbmarathon und Marathon. Eine Bestzeit ist eine Untergrenze: wer
 *   5 km in 20:00 gelaufen ist, ist mindestens so fit. Eine schnelle
 *   Teilstrecke aus einem lockeren Lauf drückt nichts nach unten, weil
 *   nur der höchste Wert zählt.
 *
 * Die 1-km-Bestzeit zählt nicht: Daniels' Formel gilt ab etwa 1,5 km, und
 * ein Kilometer aus einem Training ist oft ein Steigerungslauf.
 */
class VdotService
{
    public const EFFORT_WINDOW_DAYS = 42;

    private const EFFORT_KEYS = ['5k', '10k', 'half', 'marathon'];

    /**
     * @return array{value: float, source: string, label: string, from_threshold: float|null, from_effort: float|null}|null
     */
    public function forProfile(?RunnerProfile $profile): ?array
    {
        if (! $profile) {
            return null;
        }

        $fromThreshold = $profile->threshold_speed > 0
            ? Vdot::fromThresholdPace($profile->threshold_speed * 60)
            : null;

        $best = $this->bestEffort($profile->user_id);

        if ($fromThreshold === null && $best === null) {
            return null;
        }

        $useEffort = $best !== null && ($fromThreshold === null || $best['vdot'] > $fromThreshold);

        return [
            // Ungerundet: aus einem gerundeten VDOT käme T um eine Sekunde
            // neben der gemessenen Schwelle heraus.
            'value'          => $useEffort ? $best['vdot'] : $fromThreshold,
            'source'         => $useEffort ? 'best_effort' : 'threshold',
            'label'          => $useEffort
                ? "aus {$best['label']} in {$best['time']} am {$best['date']}"
                : 'aus der Schwellenpace ' . PaceFormat::fromMinutes($profile->threshold_speed) . ' min/km',
            'from_threshold' => $fromThreshold !== null ? round($fromThreshold, 1) : null,
            'from_effort'    => $best !== null ? round($best['vdot'], 1) : null,
        ];
    }

    /** @return array{vdot: float, label: string, time: string, date: string}|null */
    private function bestEffort(?int $userId): ?array
    {
        if (! $userId) {
            return null;
        }

        $best = null;

        $efforts = BestEffort::where('user_id', $userId)
            ->whereIn('distance_key', self::EFFORT_KEYS)
            ->where('achieved_at', '>=', now()->subDays(self::EFFORT_WINDOW_DAYS))
            ->get();

        foreach ($efforts as $e) {
            $vdot = Vdot::fromPerformance($e->distance_m ?: BestEffortService::DISTANCES[$e->distance_key], $e->elapsed_time);

            if ($vdot !== null && ($best === null || $vdot > $best['vdot'])) {
                $best = [
                    'vdot'  => $vdot,
                    'label' => BestEffortService::LABELS[$e->distance_key],
                    'time'  => RacePredictionService::clock($e->elapsed_time),
                    'date'  => $e->achieved_at->format('d.m.'),
                ];
            }
        }

        return $best;
    }
}
