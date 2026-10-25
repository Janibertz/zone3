<?php

namespace App\Services\Bike;

/**
 * Die Radeinheiten, die Zone3 plant — fest, ohne Modell.
 *
 * Eine Radeinheit ist eine Struktur in % FTP, keine Beschreibung, die erst
 * noch jemand in Schritte übersetzen muss. Das Modell würde an dieser Stelle
 * nur Zahlen erfinden, die hier schon feststehen, und es kostet bei jedem
 * Abruf Geld. Die Schritte haben dieselbe Form wie die einer Laufeinheit
 * (`type`, `label`, `duration_min`, `repetitions`), dazu `power_low` /
 * `power_high` als Anteil der FTP und — wenn die FTP bekannt ist — `watts`.
 */
class BikeWorkouts
{
    public const EASY      = 'easy';
    public const VO2       = 'vo2';
    public const THRESHOLD = 'threshold';

    /** Lockeres Rad, Zone 2: 60–75 % FTP. */
    public const EASY_LOW  = 0.60;
    public const EASY_HIGH = 0.75;

    /**
     * @return array{title: string, description: string, intensity: string, duration_min: int, steps: list<array<string, mixed>>}
     */
    public function build(string $kind, int $minutes, ?int $ftp): array
    {
        $workout = match ($kind) {
            self::VO2       => $this->vo2($minutes),
            self::THRESHOLD => $this->threshold($minutes),
            default         => $this->easy($minutes),
        };

        $workout['steps'] = array_map(fn ($s) => $this->withWatts($s, $ftp), $workout['steps']);
        $workout['duration_min'] = $this->total($workout['steps']);

        return $workout;
    }

    private function easy(int $minutes): array
    {
        $minutes = max(30, $minutes);
        $steady  = $minutes - 15;

        return [
            'title'       => 'Rad locker',
            'description' => "Gleichmäßig im Grundlagenbereich, {$this->pct(self::EASY_LOW)}–{$this->pct(self::EASY_HIGH)} % FTP. "
                . 'Ersetzt einen lockeren Lauf und schont dabei die Beine — nicht schneller fahren, nur weil es sich leicht anfühlt.',
            'intensity'   => 'low',
            'steps'       => [
                $this->step('warmup', 'Einfahren', 10, 0.50, 0.65),
                $this->step('work', 'Grundlage', $steady, self::EASY_LOW, self::EASY_HIGH),
                $this->step('cooldown', 'Ausfahren', 5, 0.65, 0.45),
            ],
        ];
    }

    /** 4-Minuten-Intervalle an der VO2max, gleich lange Pausen. */
    private function vo2(int $minutes): array
    {
        // 15 Ein-, 10 Ausfahren, je Wiederholung 8 Minuten — 4 bis 6.
        $reps = max(4, min(6, intdiv(max(0, $minutes - 25), 8)));

        return [
            'title'       => "Rad {$reps}×4 min VO2max",
            'description' => "{$reps}×4 min bei 110–120 % FTP, dazwischen 4 min locker. Die Wiederholungen sollen gleich stark sein — lieber die erste etwas zurückhalten.",
            'intensity'   => 'high',
            'steps'       => [
                $this->step('warmup', 'Einfahren', 15, 0.50, 0.75),
                $this->step('work', '4 min hart', 4, 1.10, 1.20, $reps),
                $this->step('rest', 'Locker', 4, 0.50, 0.50, $reps),
                $this->step('cooldown', 'Ausfahren', 10, 0.60, 0.45),
            ],
        ];
    }

    /** 10-Minuten-Blöcke knapp unter der FTP. */
    private function threshold(int $minutes): array
    {
        $reps = max(2, min(4, intdiv(max(0, $minutes - 25), 15)));

        return [
            'title'       => "Rad {$reps}×10 min Schwelle",
            'description' => "{$reps}×10 min bei 95–100 % FTP, dazwischen 5 min locker.",
            'intensity'   => 'high',
            'steps'       => [
                $this->step('warmup', 'Einfahren', 15, 0.50, 0.75),
                $this->step('work', '10 min Schwelle', 10, 0.95, 1.00, $reps),
                $this->step('rest', 'Locker', 5, 0.55, 0.55, $reps),
                $this->step('cooldown', 'Ausfahren', 10, 0.60, 0.45),
            ],
        ];
    }

    private function step(string $type, string $label, int $minutes, float $low, float $high, int $reps = 1): array
    {
        return [
            'type'         => $type,
            'label'        => $label,
            'duration_min' => $minutes,
            'power_low'    => $low,
            'power_high'   => $high,
            'pace_target'  => null,
            'zone'         => null,
            'repetitions'  => $reps > 1 ? $reps : null,
        ];
    }

    /** „180–225 W" neben die Prozente, sobald die FTP bekannt ist. */
    private function withWatts(array $step, ?int $ftp): array
    {
        if (! $ftp) {
            return $step + ['watts' => null];
        }

        $lo = (int) round($step['power_low'] * $ftp);
        $hi = (int) round($step['power_high'] * $ftp);

        return $step + ['watts' => $lo === $hi ? "{$lo} W" : min($lo, $hi) . '–' . max($lo, $hi) . ' W'];
    }

    private function total(array $steps): int
    {
        return array_sum(array_map(fn ($s) => $s['duration_min'] * ($s['repetitions'] ?? 1), $steps));
    }

    private function pct(float $share): int
    {
        return (int) round($share * 100);
    }
}
