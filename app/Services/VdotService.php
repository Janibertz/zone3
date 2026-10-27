<?php

namespace App\Services;

use App\Models\BestEffort;
use App\Models\GarminDailyMetric;
use App\Models\RunnerProfile;
use App\Services\PaceFormat;

/**
 * Woher der VDOT eines Athleten kommt — die einzige Stelle, die das sagt.
 *
 * Drei Quellen, und es gilt die höchste (Entscheidung vom 25.10.2026,
 * Garmin seit 27.10.2026):
 *
 * - **Schwellenpace** (`threshold_speed`, KI-berechnet aus den letzten
 *   Läufen). Trägt durch Wochen ohne schnellen Lauf.
 * - **Strava-Bestzeiten** der letzten sechs Wochen über 5 km, 10 km,
 *   Halbmarathon und Marathon. Eine Bestzeit ist eine Untergrenze: wer
 *   5 km in 20:00 gelaufen ist, ist mindestens so fit. Eine schnelle
 *   Teilstrecke aus einem lockeren Lauf drückt nichts nach unten, weil
 *   nur der höchste Wert zählt.
 * - **Garmins VO2max** (User Metrics der Health API), der neueste Wert der
 *   letzten sechs Wochen. Garmin schätzt anders als Daniels; Jan hat
 *   entschieden, den Wert trotzdem gleichrangig zu zählen. Ein zu
 *   optimistischer Garmin-Wert macht damit alle Tempi schneller.
 *
 * Die 1-km-Bestzeit zählt nicht: Daniels' Formel gilt ab etwa 1,5 km, und
 * ein Kilometer aus einem Training ist oft ein Steigerungslauf.
 */
class VdotService
{
    public const EFFORT_WINDOW_DAYS = 42;

    private const EFFORT_KEYS = ['5k', '10k', 'half', 'marathon'];

    /**
     * @return array{value: float, source: string, label: string, from_threshold: float|null, from_effort: float|null, from_garmin: float|null}|null
     */
    public function forProfile(?RunnerProfile $profile): ?array
    {
        if (! $profile) {
            return null;
        }

        $candidates = [];

        if ($profile->threshold_speed > 0) {
            $candidates['threshold'] = [
                'vdot'  => Vdot::fromThresholdPace($profile->threshold_speed * 60),
                'label' => 'aus der Schwellenpace ' . PaceFormat::fromMinutes($profile->threshold_speed) . ' min/km',
            ];
        }

        if ($best = $this->bestEffort($profile->user_id)) {
            $candidates['best_effort'] = [
                'vdot'  => $best['vdot'],
                'label' => "aus {$best['label']} in {$best['time']} am {$best['date']}",
            ];
        }

        if ($garmin = $this->garmin($profile->user_id)) {
            $candidates['garmin'] = $garmin;
        }

        $candidates = array_filter($candidates, fn ($c) => $c['vdot'] !== null);

        if ($candidates === []) {
            return null;
        }

        // Bei Gleichstand gewinnt die erste Quelle: Schwelle vor Bestzeit vor Garmin.
        $source = null;
        foreach ($candidates as $key => $c) {
            if ($source === null || $c['vdot'] > $candidates[$source]['vdot']) {
                $source = $key;
            }
        }

        $rounded = fn (string $key) => isset($candidates[$key]) ? round($candidates[$key]['vdot'], 1) : null;

        return [
            // Ungerundet: aus einem gerundeten VDOT käme T um eine Sekunde
            // neben der gemessenen Schwelle heraus.
            'value'          => $candidates[$source]['vdot'],
            'source'         => $source,
            'label'          => $candidates[$source]['label'],
            'from_threshold' => $rounded('threshold'),
            'from_effort'    => $rounded('best_effort'),
            'from_garmin'    => $rounded('garmin'),
        ];
    }

    /** @return array{vdot: float, label: string}|null */
    private function garmin(?int $userId): ?array
    {
        if (! $userId) {
            return null;
        }

        $metric = GarminDailyMetric::where('user_id', $userId)
            ->whereNotNull('vo2max')
            ->whereDate('date', '>=', now()->subDays(self::EFFORT_WINDOW_DAYS)->toDateString())
            ->orderByDesc('date')
            ->first();

        return $metric ? [
            'vdot'  => (float) $metric->vo2max,
            'label' => 'aus Garmins VO2max vom ' . $metric->date->format('d.m.'),
        ] : null;
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
