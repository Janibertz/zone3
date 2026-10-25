<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;

/**
 * Wie viel der Athlet tatsächlich locker läuft — nicht, was der Plan sagt.
 *
 * Das Gerüst sorgt dafür, dass höchstens 20 % der geplanten Laufzeit hart
 * sind. Ob der Athlet die lockeren Läufe dann auch locker läuft, ist eine
 * andere Frage, und die häufigste Abweichung bei Hobbyläufern: der lockere
 * Lauf wird zur „mittleren" Einheit, und am Ende ist die Hälfte der Woche
 * im Bereich, der weder erholt noch fordert. Seiler nennt das die
 * Schwellen-Falle.
 *
 * Gemessen wird nach Pace, Runde für Runde aus den Strava-Laps (die
 * Autolaps sind meist ein Kilometer). Hart ist alles ab Zone 3 — schneller
 * als Daniels' E-Bereich (74 % VO2max). Ohne Laps zählt der ganze Lauf mit
 * seinem Schnitt.
 *
 * Grenze der Messung: Pace kennt keine Steigung. Ein Kilometer bergab kann
 * „hart" aussehen, einer bergauf „locker". Über vier Wochen gleicht sich das
 * weitgehend aus; für eine Aussage über einen einzelnen Lauf reicht es nicht.
 */
class IntensityDistributionService
{
    public const WEEKS = 4;

    /** Ab diesem Anteil am VO2max ist ein Abschnitt hart (Beginn Zone 3). */
    public const HARD_FROM = 0.74;

    /** Darüber läuft der Athlet seine lockeren Läufe zu schnell. */
    public const TOO_HARD_PCT = 25;

    /**
     * Im norwegischen Modell sind bis zu 30 % Qualität geplant (25 %
     * Sub-Schwelle, 5 % schnell) — und Sub-Schwelle misst sich nach Pace
     * als „hart". Erst darüber ist es zu viel.
     */
    public const TOO_HARD_PCT_NORWEGIAN = 35;

    /**
     * @return array{
     *     easy_min: int, hard_min: int, hard_pct: int, runs: int, model: string, limit_pct: int,
     *     weeks: list<array{label: string, easy_min: int, hard_min: int, hard_pct: int|null}>
     * }|null
     */
    public function forUser(int $userId, ?float $vdot, ?CarbonImmutable $today = null, string $model = 'polarized'): ?array
    {
        if (! $vdot) {
            return null;
        }

        $today = $today ?? CarbonImmutable::today();
        $from  = $today->startOfWeek()->subWeeks(self::WEEKS - 1);

        // Schneller als diese Pace (Sekunden je km) ist hart.
        $hardPace = Vdot::paceAt($vdot, self::HARD_FROM);

        $runs = Activity::where('user_id', $userId)
            ->whereIn('type', TrainingSession::RUN_SPORTS)
            ->where('start_date', '>=', $from->toDateTimeString())
            ->get(['start_date', 'distance', 'moving_time', 'laps']);

        $weeks = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $start = $from->addWeeks($i);
            $weeks[$start->format('o-\WW')] = ['label' => $start->format('d.m.'), 'easy' => 0, 'hard' => 0];
        }

        foreach ($runs as $run) {
            $key = CarbonImmutable::parse($run->start_date)->format('o-\WW');
            if (! isset($weeks[$key])) {
                continue;
            }

            foreach ($this->segments($run) as [$seconds, $meters]) {
                $pace = $seconds / ($meters / 1000);
                $weeks[$key][$pace <= $hardPace ? 'hard' : 'easy'] += $seconds;
            }
        }

        $easy = array_sum(array_column($weeks, 'easy'));
        $hard = array_sum(array_column($weeks, 'hard'));

        if ($easy + $hard === 0) {
            return null;
        }

        return [
            'easy_min' => (int) round($easy / 60),
            'hard_min' => (int) round($hard / 60),
            'hard_pct' => (int) round($hard / ($easy + $hard) * 100),
            'runs'     => $runs->count(),
            'model'    => $model,
            'limit_pct' => $model === 'norwegian' ? self::TOO_HARD_PCT_NORWEGIAN : self::TOO_HARD_PCT,
            'weeks'    => array_values(array_map(fn ($w) => [
                'label'    => $w['label'],
                'easy_min' => (int) round($w['easy'] / 60),
                'hard_min' => (int) round($w['hard'] / 60),
                'hard_pct' => $w['easy'] + $w['hard'] > 0 ? (int) round($w['hard'] / ($w['easy'] + $w['hard']) * 100) : null,
            ], $weeks)),
        ];
    }

    /**
     * Die Abschnitte eines Laufs als [Sekunden, Meter]. Runden unter 200 m
     * (das Reststück am Ende, ein Ampelstopp) sagen über die Pace nichts.
     *
     * @return list<array{0: int, 1: float}>
     */
    private function segments(Activity $run): array
    {
        $laps = array_values(array_filter(
            $run->laps ?? [],
            fn ($l) => ($l['moving_time'] ?? 0) > 0 && ($l['distance'] ?? 0) >= 200,
        ));

        if ($laps) {
            return array_map(fn ($l) => [(int) $l['moving_time'], (float) $l['distance']], $laps);
        }

        return $run->moving_time > 0 && $run->distance >= 200
            ? [[(int) $run->moving_time, (float) $run->distance]]
            : [];
    }

    /** Der Abschnitt für den Plan-Prompt. */
    public function toPromptSection(?array $d): string
    {
        if (! $d) {
            return '';
        }

        $weeks = implode("\n", array_map(
            fn ($w) => $w['hard_pct'] === null
                ? "- Woche ab {$w['label']}: keine Läufe"
                : "- Woche ab {$w['label']}: {$w['easy_min']} min locker, {$w['hard_min']} min hart ({$w['hard_pct']} %)",
            $d['weeks'],
        ));

        $norwegian = ($d['model'] ?? 'polarized') === 'norwegian';
        $limit     = $d['limit_pct'] ?? self::TOO_HARD_PCT;

        $verdict = match (true) {
            $d['hard_pct'] > $limit && $norwegian
                => 'Der Athlet läuft deutlich mehr als die geplanten 30 % im Schwellenbereich und schneller — meist, weil die lockeren Läufe zu schnell werden oder die Sub-Schwelle zur Schwelle wird. Sage bei JEDEM lockeren Lauf ausdrücklich, dass er langsam sein muss, und betone bei der Sub-Schwelle den Pulsdeckel.',
            $d['hard_pct'] > $limit
                => "Der Athlet läuft deutlich mehr als 20 % hart — fast immer, weil die lockeren Läufe zu schnell werden. Sage bei JEDEM lockeren Lauf ausdrücklich, dass er langsam sein muss, und nenne die schnellste erlaubte Pace (die schnelle Grenze von Zone 2).",
            $norwegian
                => 'Die Verteilung passt zum norwegischen Modell. Halte die lockeren Läufe weiter locker.',
            default
                => 'Die Verteilung passt zu 80/20. Halte die lockeren Läufe weiter locker.',
        };

        return "\n\n**Tatsächliche Intensitätsverteilung (letzte " . self::WEEKS . " Wochen, aus Strava nach Pace):**\n"
            . "{$d['easy_min']} min locker, {$d['hard_min']} min hart — {$d['hard_pct']} % hart.\n{$weeks}\n\n{$verdict}";
    }
}
