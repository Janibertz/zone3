<?php

namespace App\Services\AI;

/**
 * Zwei Umrechnungen, die mehrere Dienste brauchen.
 *
 * Sie lagen vorher als geschützte Methoden mitten in der grossen Klasse und
 * wurden quer über die Fachbereiche hinweg aufgerufen — beim Zerlegen war
 * das die einzige Stelle, an der sich die Zuständigkeiten wirklich kreuzten.
 */
class PaceFormat
{
    /**
     * Geschwindigkeit in Metern pro Sekunde als Pace „M:SS" je Kilometer.
     *
     * Die Sekunden werden hier auf zwei Stellen aufgefuellt. Vorher fehlte
     * das: aus 5:07 wurde „5:7", und genau so stand es in den Prompts.
     */
    public static function fromSpeed(float $metersPerSecond): string
    {
        if ($metersPerSecond <= 0) {
            return '—';
        }

        $secondsPerKm = 1000 / $metersPerSecond;

        return sprintf('%d:%02d', (int) ($secondsPerKm / 60), ((int) $secondsPerKm) % 60);
    }

    /** Sekunden als „HH:MM:SS". */
    public static function hms(int $seconds): string
    {
        return sprintf(
            '%02d:%02d:%02d',
            floor($seconds / 3600),
            floor(($seconds % 3600) / 60),
            $seconds % 60,
        );
    }
}
