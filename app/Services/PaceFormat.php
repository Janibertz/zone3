<?php

namespace App\Services;

/**
 * Pace als „M:SS" — an einer Stelle.
 *
 * Diese Umrechnung stand in dreiundzwanzig Kopien im Projekt, in
 * Controllern, Jobs und Diensten. Solange alle gleich rechneten, fiel es
 * nicht auf. Sie taten es nicht:
 *
 *   · die meisten schnitten ab: 359,7 s/km → „5:59"
 *   · das Review rundete:       359,7 s/km → „6:00"
 *
 * Für denselben Lauf stand damit auf der einen Seite 5:59 und auf der
 * anderen 6:00. Das ist die Art Widerspruch, die niemand als Fehler
 * meldet und jeder bemerkt.
 *
 * Gerundet wird jetzt überall, und zwar EINMAL auf die ganze Sekunde,
 * bevor in Minuten und Sekunden zerlegt wird. Andersherum — Minute
 * abschneiden, Sekunde runden — lief die Minute daneben, sobald der Wert
 * knapp unter einer vollen lag: 359,97 ergab „5:00" statt 6:00.
 *
 * Nicht hier: Gesamtzeiten („3:26:21", „1:35 Std"). Das ist eine andere
 * Grösse mit anderer Darstellung, und sie zusammenzulegen hiesse nur, die
 * nächste Verwechslung vorzubereiten.
 */
class PaceFormat
{
    /** Was zurückkommt, wenn sich keine Pace bilden lässt. */
    public const NONE = '—';

    /**
     * Sekunden je Kilometer als „M:SS".
     *
     * Die kanonische Umrechnung — alles andere hier führt darauf zurück.
     */
    public static function fromSeconds(?float $secondsPerKm): string
    {
        if (! $secondsPerKm || $secondsPerKm <= 0 || ! is_finite($secondsPerKm)) {
            return self::NONE;
        }

        $total = (int) round($secondsPerKm);

        return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
    }

    /**
     * Eine ZIELPACE als „M:SS" — abgerundet, nicht gerundet.
     *
     * Das ist kein Schönheitsfehler, sondern eine andere Bedeutung. Eine
     * gemessene Pace beschreibt, was war: dort ist Runden genauer. Eine
     * Zielpace ist eine Anweisung, und wer sie befolgt, muss das Ziel
     * erreichen.
     *
     * 3:30 Std auf 42,195 km sind 298,6 s/km. Gerundet ergäbe das 4:59 —
     * wer das läuft, kommt auf 3:30:22 und verfehlt sein Ziel. Abgerundet
     * steht dort 4:58, und das führt auf 3:29:36.
     */
    public static function target(?float $secondsPerKm): string
    {
        if (! $secondsPerKm || $secondsPerKm <= 0 || ! is_finite($secondsPerKm)) {
            return self::NONE;
        }

        $total = (int) floor($secondsPerKm);

        return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
    }

    /** Geschwindigkeit in Metern je Sekunde als Pace „M:SS" je Kilometer. */
    public static function fromSpeed(?float $metersPerSecond): string
    {
        if (! $metersPerSecond || $metersPerSecond <= 0) {
            return self::NONE;
        }

        return self::fromSeconds(1000 / $metersPerSecond);
    }

    /**
     * Dezimalminuten als „M:SS" — das Format von `threshold_speed`.
     *
     * 5,5 sind 5:30. Mehrere Kopien rechneten hier
     * `(int) $m . ':' . round(($m - (int) $m) * 60)` und konnten damit
     * „4:60" erzeugen: die Sekunden runden auf sechzig auf, ohne dass die
     * Minute mitzählt. Über die Sekunden zu gehen schliesst das aus.
     */
    public static function fromMinutes(?float $minutes): string
    {
        if (! $minutes || $minutes <= 0) {
            return self::NONE;
        }

        return self::fromSeconds($minutes * 60);
    }

    /** Sekunden als „HH:MM:SS". */
    public static function hms(int $seconds): string
    {
        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60,
        );
    }
}
