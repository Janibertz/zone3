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
 * Garmin seit 27.10.2026, nur ohne Messung seit 17.11.2026):
 *
 * - **Schwellenpace** (`threshold_speed`, KI-berechnet aus den letzten
 *   Läufen). Trägt durch Wochen ohne schnellen Lauf.
 * - **Strava-Bestzeiten** der letzten sechs Wochen über 5 km, 10 km,
 *   Halbmarathon und Marathon. Eine Bestzeit ist eine Untergrenze: wer
 *   5 km in 20:00 gelaufen ist, ist mindestens so fit. Eine schnelle
 *   Teilstrecke aus einem lockeren Lauf drückt nichts nach unten, weil
 *   nur der höchste Wert zählt.
 * - **VO2max der Uhr** (Garmin User Metrics, COROS, Polar, Apple Health),
 *   der neueste Wert der letzten sechs Wochen. **Zählt seit 17.11.2026 nur,
 *   wenn es keine gemessene Schwelle gibt** (Jan): Garmin schätzt anders
 *   als Daniels, und ein zu optimistischer Wert machte alle Tempi schneller,
 *   obwohl Läufe und Garmins eigene Laktatschwelle langsamer lagen.
 *
 * Die 1-km-Bestzeit zählt nicht: Daniels' Formel gilt ab etwa 1,5 km, und
 * ein Kilometer aus einem Training ist oft ein Steigerungslauf.
 */
class VdotService
{
    public const EFFORT_WINDOW_DAYS = 42;

    private const EFFORT_KEYS = ['5k', '10k', 'half', 'marathon'];

    /**
     * @return array{value: float, source: string, label: string, from_threshold: float|null, from_effort: float|null, from_garmin: float|null, candidates: array<string, array{vdot: float, label: string}>, ignored: list<string>}|null
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

        // Die VO2max der Uhr zählt nur ohne gemessene Schwelle (Jan,
        // 17.11.2026): Garmin schätzte 51, Läufe und Garmins eigene
        // Laktatschwelle sagten beide 4:21 (VDOT 48,6) — die Anzeige stand
        // trotzdem auf 4:11. Sie bleibt als Hinweis in der Liste.
        $counting = isset($candidates['threshold'])
            ? array_diff_key($candidates, ['garmin' => true])
            : $candidates;

        // Bei Gleichstand gewinnt die erste Quelle: Schwelle vor Bestzeit vor Garmin.
        $source = null;
        foreach ($counting as $key => $c) {
            if ($source === null || $c['vdot'] > $counting[$source]['vdot']) {
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
            // Alle Quellen mit Wert, für die Erklärung unter „Prognosen &
            // Verlauf" (Jan, 17.11.2026: die 4:11 neben gemessenen 4:21
            // war ohne die drei Quellen nicht zu verstehen).
            'candidates'     => array_map(fn ($c) => ['vdot' => $c['vdot'], 'label' => $c['label']], $candidates),
            // Quellen, die nicht mitzählen (die VO2max neben einer Messung).
            'ignored'        => array_keys(array_diff_key($candidates, $counting)),
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
            'label' => match ($metric->source) {
                'apple' => 'aus der VO2max von Apple Health vom ',
                'coros' => 'aus der VO2max deiner COROS-Uhr vom ',
                'polar' => 'aus der VO2max deiner Polar-Uhr vom ',
                default => 'aus Garmins VO2max vom ',
            } . $metric->date->format('d.m.'),
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
