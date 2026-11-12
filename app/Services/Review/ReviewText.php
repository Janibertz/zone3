<?php

namespace App\Services\Review;

/**
 * Prüft die Nachricht des Coaches nach einer Einheit, bevor sie rausgeht.
 *
 * Der Prompt allein reicht nicht — das hat der Plan gezeigt („Die
 * Beschreibung trägt keine Zahlen", und das Modell schrieb trotzdem
 * „2×12:30 bei exakt 4:20"). Was hier anschlägt, geht einmal zurück ans
 * Modell; die zweite Antwort gilt.
 */
class ReviewText
{
    /** Wörter aus dem System, nicht aus dem Mund eines Trainers. */
    public const BANNED = [
        'Alternativtraining', 'Abweichung', 'Einordnung', 'TSB', 'Laufumfang',
        'Laufkilometer', 'Umsetzungsquote', 'bpm', 'Ø', 'min/km',
        'Datenlage', 'laut Daten', 'Trainingsbelastung',
    ];

    public const MAX_WORDS_PER_SENTENCE = 25;

    /** Zahlen, die der Athlet als Messwert liest — Wochentage oder „1:30" als Ziel zählen mit. */
    public const MAX_NUMBERS = 2;

    /**
     * @return list<string>  Was nicht passt, in Worten für die Nachfrage
     */
    public static function problems(string $text, int $maxSentences, bool $emojiAllowed): array
    {
        $out = [];
        $sentences = self::sentences($text);

        if (count($sentences) > $maxSentences) {
            $out[] = 'zu lang: ' . count($sentences) . " Sätze, höchstens {$maxSentences}";
        }

        foreach ($sentences as $s) {
            if (str_word_count($s, 0, 'äöüÄÖÜß') > self::MAX_WORDS_PER_SENTENCE) {
                $out[] = 'ein Satz hat mehr als ' . self::MAX_WORDS_PER_SENTENCE . ' Wörter';
                break;
            }
        }

        $numbers = preg_match_all('/\d+(?:[.,:]\d+)*/u', $text);
        if ($numbers > self::MAX_NUMBERS) {
            $out[] = "{$numbers} Zahlen, höchstens " . self::MAX_NUMBERS;
        }

        foreach (self::BANNED as $word) {
            if (mb_stripos($text . ' ', $word) !== false) {
                $out[] = 'Wort „' . trim($word) . '" gehört nicht in die Nachricht';
            }
        }

        $emojis = preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text);
        if ($emojis > ($emojiAllowed ? 1 : 0)) {
            $out[] = $emojiAllowed ? 'höchstens ein Emoji' : 'kein Emoji';
        }

        return $out;
    }

    /** @return list<string> */
    public static function sentences(string $text): array
    {
        // „4:15" oder „z. B." trennen keinen Satz; nur Punkt, Ausrufe- oder
        // Fragezeichen vor Leerraum oder Ende.
        $parts = preg_split('/(?<=[.!?…])\s+/u', trim($text)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }
}
