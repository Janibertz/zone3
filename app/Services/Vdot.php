<?php

namespace App\Services;

/**
 * Jack Daniels' VDOT — eine Zahl für die ganze Form.
 *
 * Vorher hingen alle Tempos an festen Faktoren auf die Schwellenpace: 5 km
 * = T × 0,90, Marathon = T × 1,12, locker = T + 46 bis 81 s. Die Faktoren
 * waren geschätzt und lagen daneben — bei T = 4:15 sagte Zone3 für 5 km
 * 19:08 voraus (Daniels: 19:56) und für den Marathon 3:20:51 (3:10:39).
 * Und ein fester Sekundenabstand bedeutet für einen 3:30-Läufer etwas
 * anderes als für einen 6:00-Läufer.
 *
 * Die zwei Formeln aus „Daniels' Running Formula" (Daniels/Gilbert):
 *
 *   VO2(v)   = −4,60 + 0,182258·v + 0,000104·v²      v in m/min
 *   %max(t)  = 0,8 + 0,1894393·e^(−0,012778·t) + 0,2989558·e^(−0,1932605·t)   t in min
 *   VDOT     = VO2(v) / %max(t)
 *
 * Sie reproduzieren Daniels' Tabellen auf wenige Sekunden. Gültig für
 * Leistungen zwischen etwa 1,5 km und dem Marathon — deshalb zählt eine
 * 1-km-Bestzeit hier nicht.
 *
 * Reine Mathematik, kein Zustand. Woher der VDOT eines Athleten kommt,
 * entscheidet {@see VdotService}.
 */
final class Vdot
{
    /**
     * Die Pace-Zonen als Anteil am VO2max — [schnelle Grenze, langsame Grenze].
     *
     * Zone 2 ist Daniels' E-Bereich (59–74 %), Zone 4 endet an T (88 %) und
     * reicht bis 90 %, damit ein 10-km-Rennen (~92 %) in Zone 5 landet und
     * ein Halbmarathon (~88 %) in Zone 4.
     */
    public const ZONES = [
        1 => [0.59, null],
        2 => [0.74, 0.59],
        3 => [0.83, 0.74],
        4 => [0.90, 0.83],
        5 => [null, 0.90],
    ];

    /**
     * Das Tempo, nach dem eine Einheit in dieser Zone gelaufen wird.
     *
     * Nicht die Mitte des Bereichs: Zone 4 heisst Schwelle, also T (88 %),
     * und Zone 5 heisst Intervall, also I (97,5 %).
     */
    public const ZONE_TARGET = [1 => 0.55, 2 => 0.66, 3 => 0.79, 4 => 0.88, 5 => 0.975];

    /** Daniels' Intensitäten der Trainingstempi. */
    public const EASY       = [0.74, 0.59];
    public const LONG       = [0.70, 0.59];
    public const THRESHOLD  = 0.88;
    public const INTERVAL   = [1.00, 0.975];
    public const REPETITION = 1.05;

    /** VDOT aus einer Leistung: Strecke in Metern, Zeit in Sekunden. */
    public static function fromPerformance(float $meters, float $seconds): ?float
    {
        if ($meters <= 0 || $seconds <= 0) {
            return null;
        }

        $minutes = $seconds / 60;

        return self::vo2($meters / $minutes) / self::fractionSustained($minutes);
    }

    /**
     * VDOT aus der Schwellenpace. T ist per Definition das Tempo bei 88 %
     * VO2max — die Umkehrung ist exakt, T bleibt T.
     */
    public static function fromThresholdPace(float $secondsPerKm): ?float
    {
        if ($secondsPerKm <= 0) {
            return null;
        }

        return self::vo2(60000 / $secondsPerKm) / self::THRESHOLD;
    }

    /** Sekunden je Kilometer bei einem Anteil am VO2max. */
    public static function paceAt(float $vdot, float $fraction): float
    {
        return 60000 / self::velocityFor($vdot * $fraction);
    }

    /**
     * Die Zeit, die diese Form über eine Strecke hergibt.
     *
     * Die Formel lässt sich nicht nach t auflösen; Bisektion konvergiert in
     * 60 Schritten weit unter einer Sekunde.
     */
    public static function raceSeconds(float $vdot, float $meters): float
    {
        [$lo, $hi] = [60.0, 60.0 * 60 * 30];

        for ($i = 0; $i < 60; $i++) {
            $mid = ($lo + $hi) / 2;

            // Mehr Zeit → kleinerer VDOT. Liegt der VDOT bei $mid noch über
            // dem gesuchten, ist $mid zu schnell.
            self::fromPerformance($meters, $mid) > $vdot ? $lo = $mid : $hi = $mid;
        }

        return ($lo + $hi) / 2;
    }

    private static function vo2(float $metersPerMinute): float
    {
        return -4.60 + 0.182258 * $metersPerMinute + 0.000104 * $metersPerMinute ** 2;
    }

    private static function fractionSustained(float $minutes): float
    {
        return 0.8 + 0.1894393 * exp(-0.012778 * $minutes) + 0.2989558 * exp(-0.1932605 * $minutes);
    }

    /** Umkehrung von vo2(): die Geschwindigkeit in m/min. */
    private static function velocityFor(float $vo2): float
    {
        [$a, $b, $c] = [0.000104, 0.182258, -4.60 - $vo2];

        return (-$b + sqrt($b * $b - 4 * $a * $c)) / (2 * $a);
    }
}
