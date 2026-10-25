<?php

namespace App\Services\Bike;

use App\Models\RunnerProfile;
use Carbon\CarbonImmutable;

/**
 * Welche Läufe des Gerüsts aufs Rad wandern (Entscheidung vom 25.10.2026).
 *
 *   1. Rad ersetzt einen lockeren Lauf — bevorzugt an den Wunschtagen,
 *      sonst an irgendeinem Tag mit lockerem Lauf —, bis zum Wochenmaximum.
 *      Der lange Lauf wird nie ersetzt.
 *   2. Höchstens ein Radintervall pro Woche, und zwar statt der
 *      VO2-Intervalle, nicht statt Tempo- oder Schwellenlauf. Es bleibt eine
 *      harte Einheit: Abstand und Ruhetag danach stehen schon, die harten
 *      Minuten aus 80/20 deckeln die Wiederholungen.
 *   3. Norwegisches Modell: die Doppel-Schwelle bleibt Laufen. Rad darf nur
 *      lockere Läufe und die kurze schnelle Einheit ersetzen — die wird auf
 *      dem Rad 1-Minuten-Stöße, sofern ihr 5-%-Anteil fünf davon trägt.
 *   4. In Taper und Rennwoche keine Radintervalle, lockeres Rad schon.
 *   5. Die Kilometer eines Radtags fallen aus dem Laufumfang der Woche und
 *      werden nicht auf andere Läufe verteilt.
 *
 * Läuft deshalb NACH Umfangsbudget und 80/20: die Laufeinheit hat dann ihre
 * Minuten und ihren Hauptteil, und mit dem Tausch verschwinden ihre
 * Kilometer einfach aus der Woche (Regel 5).
 *
 * Die Einheit selbst kommt aus {@see BikeWorkouts} und hängt fertig am Slot
 * (`workout`) — das Modell schreibt keine Radeinheit, der Validator setzt sie
 * ein.
 */
class BikeSchedule
{
    /** November bis März. */
    private const WINTER_MONTHS = [11, 12, 1, 2, 3];

    /** Ein Radintervall unter drei Wiederholungen ist kein Reiz mehr. */
    private const MIN_VO2_REPS = 3;

    /** Die schnelle Einheit braucht mindestens fünf Stöße. */
    private const MIN_FAST_REPS = 5;

    private const ISO_TO_KEY = [
        1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday',
        5 => 'friday', 6 => 'saturday', 7 => 'sunday',
    ];

    public function __construct(private readonly BikeWorkouts $workouts) {}

    /**
     * Die Rad-Einstellungen des Athleten in der Form, die das Gerüst liest —
     * oder null, wenn er kein Rad will.
     */
    public static function settingsFor(?RunnerProfile $profile): ?array
    {
        if (! $profile?->bike_enabled) {
            return null;
        }

        return [
            'season'         => $profile->bike_season ?: 'winter',
            'purpose'        => $profile->bike_purpose ?: 'both',
            'max_per_week'   => max(1, (int) ($profile->bike_max_per_week ?: 2)),
            'preferred_days' => array_values($profile->bike_preferred_days ?? []),
            'ftp'            => $profile->ftp ? (int) $profile->ftp : null,
        ];
    }

    /**
     * @param  array<string,array>         $days     Gerüsttage, werden verändert
     * @param  array<string,list<string>>  $weeks    Wochenschlüssel → Daten
     * @param  array<string,array>         $periods  Phase je Woche
     */
    public function apply(array &$days, array $weeks, array $settings, array $periods): void
    {
        $intervals = in_array($settings['purpose'], ['intervals', 'both'], true);
        $easy      = in_array($settings['purpose'], ['easy', 'both'], true);
        $preferred = array_flip($settings['preferred_days']);

        foreach ($weeks as $weekKey => $dates) {
            $left  = $settings['max_per_week'];
            $phase = $periods[$weekKey]['phase'] ?? 'build';

            $candidates = fn (callable $fits) => $this->candidates($days, $dates, $settings, $preferred, $fits);

            if ($intervals && ! in_array($phase, ['taper', 'race_week', 'past'], true)) {
                foreach ($candidates(fn ($slot) => $slot['type'] === 'interval') as [$date, $i]) {
                    // Die kurze schnelle Einheit des norwegischen Modells wird
                    // auf dem Rad wieder kurz und schnell, kein VO2-Block.
                    $swapped = ! empty($days[$date]['slots'][$i]['fast'])
                        ? $this->toFast($days[$date], $i, $settings['ftp'])
                        : $this->toVo2($days[$date], $i, $settings['ftp']);

                    if ($swapped) {
                        $left--;
                        break;
                    }
                }
            }

            if ($easy) {
                foreach ($candidates(fn ($slot) => $slot['type'] === 'easy_run') as [$date, $i]) {
                    if ($left <= 0) {
                        break;
                    }

                    $this->toEasy($days[$date], $i, $settings['ftp']);
                    $left--;
                }
            }
        }
    }

    /**
     * Die Slots, die aufs Rad dürfen — Wunschtage zuerst, dann nach Datum.
     *
     * Nie: ein Rennen, ein fester Termin, ein Wiedereinstiegs-Lauf (er soll
     * die Beine wieder ans Laufen gewöhnen), eine Hälfte der Doppel-Schwelle
     * oder eine Sub-Schwelle (Regel 3). Der lange Lauf ist schon am Typ raus.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function candidates(array $days, array $dates, array $settings, array $preferred, callable $fits): array
    {
        $found = [];

        foreach ($dates as $date) {
            $day = $days[$date];

            if (! $day['available'] || ! empty($day['finalized']) || ! $this->inSeason($date, $settings['season'])) {
                continue;
            }

            foreach ($day['slots'] ?? [] as $i => $slot) {
                if (! empty($slot['fixed']) || ! empty($slot['race']) || isset($slot['comeback'])
                    || ! empty($slot['double']) || ! empty($slot['controlled']) || ! empty($slot['optional'])
                    || ! $fits($slot)) {
                    continue;
                }

                $found[] = [$date, $i, isset($preferred[self::ISO_TO_KEY[$day['weekday']]])];
            }
        }

        usort($found, fn ($a, $b) => [$b[2], $a[0]] <=> [$a[2], $b[0]]);

        return array_map(fn ($f) => [$f[0], $f[1]], $found);
    }

    private function inSeason(string $date, string $season): bool
    {
        return $season === 'all_year'
            || in_array(CarbonImmutable::parse($date)->month, self::WINTER_MONTHS, true);
    }

    private function toEasy(array &$day, int $i, ?int $ftp): void
    {
        $slot    = $day['slots'][$i];
        $cap     = (int) ($slot['max_min'] ?: $day['budget_min']);
        $run     = (int) ($slot['target_min'] ?? 0) ?: min($cap ?: 45, 45);
        $workout = $this->workouts->build(BikeWorkouts::EASY, $this->workouts->easyMinutesFor($run, $cap), $ftp);

        $this->replace($day, $i, $slot, $workout, BikeWorkouts::EASY, false);
    }

    /** @return bool ob getauscht wurde — passt kein Reiz in den Tag, bleibt der Lauf. */
    private function toVo2(array &$day, int $i, ?int $ftp): bool
    {
        $slot = $day['slots'][$i];
        $cap  = (int) ($slot['max_min'] ?: $day['budget_min']);

        // 25 Minuten Ein- und Ausfahren, je Wiederholung 8.
        $maxReps = $cap > 0 ? intdiv(max(0, $cap - 25), 8) : 6;

        // 80/20: der Hauptteil, den das Gerüst der Laufeinheit gelassen hat.
        if (isset($slot['hard_min'])) {
            $maxReps = min($maxReps, intdiv((int) $slot['hard_min'], BikeWorkouts::VO2_REP_MINUTES));
        }

        if ($maxReps < self::MIN_VO2_REPS) {
            return false;
        }

        $minutes = (int) ($slot['target_min'] ?? 0) ?: ($cap ?: 60);
        $workout = $this->workouts->build(BikeWorkouts::VO2, $minutes, $ftp, $maxReps);

        $this->replace($day, $i, $slot, $workout, BikeWorkouts::VO2, true);

        return true;
    }

    /** Wie toVo2(), aber 1-Minuten-Stöße — jede Wiederholung eine schnelle Minute. */
    private function toFast(array &$day, int $i, ?int $ftp): bool
    {
        $slot = $day['slots'][$i];
        $cap  = (int) ($slot['max_min'] ?: $day['budget_min']);

        $maxReps = $cap > 0 ? intdiv(max(0, $cap - 25), 3) : 10;
        if (isset($slot['hard_min'])) {
            $maxReps = min($maxReps, (int) $slot['hard_min']);
        }

        if ($maxReps < self::MIN_FAST_REPS) {
            return false;
        }

        $minutes = (int) ($slot['target_min'] ?? 0) ?: ($cap ?: 55);
        $this->replace($day, $i, $slot, $this->workouts->build(BikeWorkouts::FAST, $minutes, $ftp, $maxReps), BikeWorkouts::FAST, true);

        return true;
    }

    private function replace(array &$day, int $i, array $slot, array $workout, string $kind, bool $hard): void
    {
        $work = collect($workout['steps'])->firstWhere('type', 'work');
        $hardMin = ($work['repetitions'] ?? 1) * $work['duration_min'];

        $day['slots'][$i] = [
            'type'       => 'cross_training',
            'sport'      => 'VirtualRide',
            'bike'       => $kind,
            'replaces'   => $slot['type'],
            'hard'       => $hard,
            'max_min'    => $slot['max_min'] ?? 0,
            'target_min' => $workout['duration_min'],
            'workout'    => $workout,
        ] + ($hard ? ['hard_min' => $hardMin] : []);

        // Eine Krafteinheit daneben passt nur, wenn das Rad Platz lässt —
        // die Radeinheit ist länger als der Lauf, den sie ersetzt.
        $rest = (int) $day['budget_min'] - $workout['duration_min'];
        if ($day['budget_min'] > 0 && $rest < 25) {
            $day['slots'] = array_values(array_filter($day['slots'], fn ($s) => empty($s['optional'])));
        }
    }
}
