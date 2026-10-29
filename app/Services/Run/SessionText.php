<?php

namespace App\Services\Run;

/**
 * Die Beschreibung einer Laufeinheit trägt Zweck und Gefühl — keine Zahlen.
 *
 * Gemeldet am 29.10.2026, nach dem Umbau auf den Katalog: links „2×12:30 min
 * bei exakt 4:20, zusammen 55 min", rechts der Ablauf mit 25 min am Stück
 * in 60 min. Zwei Stellen beschrieben dieselbe Einheit, und die eine war
 * Text der KI. Der Prompt verbietet Zahlen seitdem, aber ein Prompt ist
 * eine Bitte. Hier wird es durchgesetzt: jeder Satz, der Minuten, Strecken,
 * Paces oder Wiederholungen nennt, fällt weg. Die Zahlen stehen im Ablauf.
 */
class SessionText
{
    /**
     * „4:20 min/km", „4:59–5:58", „2×12", „5 x 1000", „25 min", „400 m",
     * „12,5 km", „25 Schwellenminuten". Eine Uhrzeit ohne Einheit („dein Weg
     * zur 1:25") bleibt: das ist das Ziel, keine Vorgabe für diese Einheit.
     */
    private const STRUCTURE = '/\d{1,2}:\d{2}\s*(?:min|\/\s*km)|\d{1,2}:\d{2}\s*[–-]\s*\d{1,2}:\d{2}|\d+\s*[×x]\s*\d|\d+(?:[.,]\d+)?\s*(?:min\b|minuten|sek|sekunden|km\b|m\b|meter)|\d+\s*[a-zäöüß]+minuten/iu';

    public static function hasStructure(?string $text): bool
    {
        return $text !== null && preg_match(self::STRUCTURE, $text) === 1;
    }

    /**
     * Die Sätze ohne Zahlen behalten. Bleibt nichts übrig, gilt der Zweck
     * des Bausteins aus dem Katalog.
     */
    public static function clean(?string $text, ?string $variant = null): ?string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept      = array_filter($sentences, fn ($s) => ! self::hasStructure($s));
        $clean     = trim(implode(' ', $kept));

        if ($clean !== '') {
            return $clean;
        }

        return RunWorkouts::PURPOSE[$variant] ?? ($text === null ? null : '');
    }
}
