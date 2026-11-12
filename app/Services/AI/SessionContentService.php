<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * Inhalte einer einzelnen Einheit: Coach-Review, Tagesform-Anpassung, Backyard-
 * Schritte und die Strecke einer Schritteliste. Den Ablauf eines Laufs baut seit 29.10.2026
 * der Katalog ({@see \App\Services\Run\RunWorkouts}), nicht mehr das Modell.
 */
class SessionContentService
{
    use TalksToOpenAI;

    /**
     * Die Strecke einer Schritteliste in km, Wiederholungen eingerechnet.
     *
     * Eine vorgegebene Strecke (distance_m) zaehlt genau; sonst Dauer durch
     * Pace (bei einem Bereich die Mitte). Ein Abschnitt ohne Pace — die
     * Trabpause — laeuft im Tempo des Ein-/Auslaufens, denn getrabt wird
     * auch dort. Ohne jede Pace gibt es keine Zahl (null).
     *
     * Vorher rechnete nur das Frontend so, und nur in der Detailansicht:
     * die Liste zeigte die Zahl aus dem Wochenbudget (Minuten mal eine
     * Durchschnittspace, 10,7 km), die Details die Schaetzung aus den
     * Abschnitten (12 km). Jetzt gibt es eine Zahl, und sie steht in der
     * Datenbank.
     */
    public static function stepsTotalKm(array $steps): ?float
    {
        $paceOf = function ($pace): ?float {
            if (! $pace || $pace === 'null') return null;
            if (! preg_match_all('/(\d+):(\d{1,2})/', (string) $pace, $mm, PREG_SET_ORDER)) return null;
            $vals = array_map(fn ($p) => (int) $p[1] + (int) $p[2] / 60, $mm);

            return array_sum($vals) / count($vals);
        };

        $easy = null;
        foreach ($steps as $s) {
            if (in_array($s['type'] ?? '', ['warmup', 'cooldown'], true) && ($p = $paceOf($s['pace_target'] ?? null))) {
                $easy = max($easy ?? 0, $p);
            }
        }

        // Ohne Ein-/Auslaufen (lockerer Lauf mit Steigerungen) trabt die
        // Pause im langsamsten Tempo eines Zeitabschnitts — sonst zaehlten
        // 5 min Traben als 0 km. Eine Streckenvorgabe sagt darueber nichts.
        if ($easy === null) {
            foreach ($steps as $s) {
                if (empty($s['distance_m']) && ($p = $paceOf($s['pace_target'] ?? null))) {
                    $easy = max($easy ?? 0, $p);
                }
            }
        }

        $km = 0.0;
        foreach ($steps as $s) {
            $reps = max(1, (int) ($s['repetitions'] ?? 1));

            if (($s['distance_m'] ?? 0) > 0) {
                $km += $reps * $s['distance_m'] / 1000;
                continue;
            }

            $pace = $paceOf($s['pace_target'] ?? null) ?? (($s['type'] ?? '') === 'rest' ? $easy : null);
            $min  = (float) ($s['duration_min'] ?? 0);

            if ($pace > 0 && $min > 0) {
                $km += $reps * $min / $pace;
            }
        }

        return $km > 0 ? round($km, 1) : null;
    }

    /**
     * Build the step structure for a Backyard "yard simulation" deterministically.
     *
     * A "yard" is one Backyard loop of {@see Event::BACKYARD_LAP_KM} km, started on every
     * full hour: run the loop deliberately slowly, then rest the remainder of the hour
     * (eat/drink/recover) before the next one. So the structure is simply N × (run loop +
     * rest of hour) — no warmup, no cooldown. The frontend derives total distance/duration
     * from these steps, so they stay consistent with the displayed loop distance and pace.
     */
    public function buildYardSimulationSteps(\App\Models\TrainingSession $session): array
    {
        $lapKm = \App\Models\Event::BACKYARD_LAP_KM; // 6.706 km per loop

        // Number of loops: prefer an explicit "N-Yard"/"N Yards" mention in the title or
        // description, else derive from the planned distance, else fall back to 3.
        $yards    = null;
        $haystack = trim(($session->title ?? '') . ' ' . ($session->description ?? ''));
        if (preg_match('/(\d+)\s*[-\s]?yards?/i', $haystack, $m)) {
            $yards = (int) $m[1];
        } elseif ($session->distance_km) {
            $yards = (int) round($session->distance_km / $lapKm);
        }
        $yards = max(2, min(24, $yards ?: 3));

        // Run pace per km (midpoint of a range like "6:40-7:30"); fall back to 7:00/km.
        $paceMin = $this->parsePaceToMinutes($session->pace_target) ?? 7.0;

        // Minutes to run one loop, clamped so the rest-of-hour stays realistic.
        $runMin  = (int) round($paceMin * $lapKm);
        $runMin  = max(30, min(54, $runMin));
        $restMin = 60 - $runMin; // hourly rhythm: each loop is started on the full hour

        $kmLabel = number_format($lapKm, 1, ',', '.'); // "6,7"

        return [
            [
                'type'         => 'work',
                'label'        => "Laufen · {$kmLabel} km",
                'duration_min' => $runMin,
                'pace_target'  => ($session->pace_target && $session->pace_target !== 'null') ? $session->pace_target : null,
                'zone'         => 2,
                'repetitions'  => $yards,
                'group_label'  => 'Yard',
            ],
            [
                'type'         => 'rest',
                'label'        => 'Pause bis zur vollen Stunde',
                'duration_min' => $restMin,
                'pace_target'  => null,
                'zone'         => 1,
                'repetitions'  => $yards,
            ],
        ];
    }

    /** Parse a pace string ("M:SS" or a range "M:SS-M:SS") to minutes/km; range → midpoint. Null if unparseable. */

    private function parsePaceToMinutes(?string $pace): ?float
    {
        if (! $pace || $pace === 'null') return null;
        if (! preg_match_all('/(\d+):(\d{1,2})/', $pace, $mm, PREG_SET_ORDER)) return null;
        $vals = array_map(fn ($p) => (int) $p[1] + (int) $p[2] / 60, $mm);
        return array_sum($vals) / count($vals);
    }

    /**
     * Die Nachricht des Coaches nach einer Einheit.
     *
     * Bis 12.11.2026 bekam das kleinste Modell alle Messwerte und die
     * Anweisung „konkret mit Zahlen, beginne mit der Einordnung" — es las die
     * Daten vor, samt der Steuerzeilen, die nur für das Modell gedacht waren
     * (Jan: „hört sich nicht so an, als käme sie von einem echten Trainer").
     * Jetzt sagt der Code, worüber geschrieben wird
     * ({@see \App\Services\Review\SessionHighlights}), das mittlere Modell
     * formuliert, und {@see \App\Services\Review\ReviewText} prüft danach.
     *
     * @param  array{key: string, main: string, side: ?string, length: string, emoji: bool}  $highlight
     * @return array{review:string, question:?string, options:array<string>}|null
     */
    public function generateSessionReview(
        \App\Models\User $user,
        string $sessionLabel,
        array $highlight,
        string $context,
        string $factsBlock,
    ): ?array {
        $messages = [
            ['role' => 'system', 'content' => $this->ai->systemPrompt(self::REVIEW_SYSTEM)],
            ['role' => 'user',   'content' => self::reviewPrompt($sessionLabel, $highlight, $context, $factsBlock)],
        ];

        $this->forUser($user->id);

        $max   = \App\Services\Review\SessionHighlights::SENTENCES[$highlight['length']] ?? 3;
        $data  = $this->askForReview($messages);
        $issues = $data ? \App\Services\Review\ReviewText::problems($data['review'], $max, $highlight['emoji']) : [];

        // Einmal nachbessern lassen; die zweite Antwort gilt, auch wenn sie
        // nicht perfekt ist — eine etwas lange Nachricht ist besser als keine.
        if ($data && $issues) {
            $retry = $this->askForReview([
                ...$messages,
                ['role' => 'assistant', 'content' => json_encode($data, JSON_UNESCAPED_UNICODE)],
                ['role' => 'user', 'content' => 'Das klingt noch nicht wie ein Trainer: ' . implode('; ', $issues)
                    . '. Schreib die Nachricht neu, gleiche Form.'],
            ]);
            $data = $retry ?? $data;
        }

        return $data;
    }

    /** @return array{review:string, question:?string, options:array<string>}|null */
    private function askForReview(array $messages): ?array
    {
        $text = $this->ai->chat('session_review', $messages, 0.7, 1500, 60, $this->ai->mid(), background: true);
        $data = $this->ai->jsonObject($text);

        if (empty($data['review'])) {
            return null;
        }

        $question = ! empty($data['question']) && $data['question'] !== 'null' ? trim($data['question']) : null;
        $options  = (! empty($data['options']) && is_array($data['options']))
            ? array_values(array_slice(array_filter(array_map(fn ($o) => trim((string) $o), $data['options'])), 0, 4))
            : [];

        // Eine Frage ohne Antworten (oder umgekehrt) ist kein Dialog.
        if (! $question || count($options) < 2) {
            $question = null;
            $options  = [];
        }

        return ['review' => trim($data['review']), 'question' => $question, 'options' => $options];
    }

    private const REVIEW_SYSTEM = <<<'TXT'
Du schreibst deinem Athleten nach dem Training eine kurze Nachricht, so wie ein guter Trainer per Messenger schreibt: persönlich, kurz, auf Deutsch, in der Du-Form. Antworte nur mit dem verlangten JSON.
TXT;

    /**
     * Der Prompt für die Nachricht nach einer Einheit. Öffentlich, damit der
     * Vergleichsbefehl und die Tests ihn ohne Modellaufruf ansehen können.
     */
    public static function reviewPrompt(string $sessionLabel, array $highlight, string $context, string $factsBlock): string
    {
        $max  = \App\Services\Review\SessionHighlights::SENTENCES[$highlight['length']] ?? 3;
        $side = $highlight['side'] ? "\nNebenpunkt (nur wenn er in einen Halbsatz passt): {$highlight['side']}" : '';
        $emoji = $highlight['emoji'] ? 'Höchstens ein Emoji, wenn es passt.' : 'Kein Emoji.';
        $banned = implode(', ', array_map('trim', \App\Services\Review\ReviewText::BANNED));

        return <<<PROMPT
Einheit: {$sessionLabel}

WORÜBER DU SCHREIBST
Hauptpunkt: {$highlight['main']}{$side}

WAS DU ÜBER DEN ATHLETEN WEISST
{$context}

MESSWERTE (nur zum Nachschlagen, NICHT aufzählen)
{$factsBlock}

SO SCHREIBST DU
- Höchstens {$max} Sätze. Jeder Satz kurz, höchstens 20 Wörter. Alltagssprache.
- Schreib über den Hauptpunkt. Was er bedeutet, nicht welche Werte gemessen wurden.
- Höchstens zwei Zahlen in der ganzen Nachricht, und nur, wenn sie etwas sagen. Lieber "sechs Schläge weniger" als "132 statt 138 bpm".
- Wenn es passt, ein Blick nach vorn: die nächste Einheit oder das Ziel. Nicht jedes Mal.
- Den Vornamen höchstens ab und zu, nicht als Anrede am Anfang.
- Fang nicht so an wie deine letzten Nachrichten. Kein "Starke Leistung", kein "Super gemacht" als Einstieg, keine Wiederholung des Einheitennamens am Anfang.
- Diese Wörter benutzt du nicht: {$banned}. Keine Abkürzungen wie "Ø".
- Ist eine Pause oder ein späterer Planstart genannt, wertest du nichts als Lücke oder Unzuverlässigkeit.
- {$emoji}
- Ehrlich, aber nie belehrend oder abwertend.

RÜCKFRAGE
Nur wenn die Antwort etwas für das weitere Training ändert (Ursache für hohen Puls, Gefühl nach dem ersten Lauf nach einer Pause, zu schnelle Schlüsseleinheit): genau eine kurze Frage als letzter Satz der Nachricht in "question" (nicht in "review"), dazu 2–4 Antworten mit höchstens drei Wörtern. Sonst question null und options [].

SO KLINGT ES (Beispiele für den Ton, nicht zum Abschreiben)
- "Gleiches Tempo wie vor einem Monat, aber sechs Schläge weniger Puls. Genau daraus wird deine Zielzeit. Donnerstag kommt die Schwelle, bis dahin nichts Schnelles."
- "Stark gelaufen, aber zu schnell. Die Schwelle wirkt über die Dauer, nicht über das Tempo. Nächstes Mal genau auf Zielpace." Frage: "Wie fühlen sich die Beine an?" Antworten: Frisch, Schwer, Geht so
- "Erster Lauf nach der Pause, ganz ruhig. Genau so." Frage: "Wie geht es dir danach?" Antworten: Gut, Etwas müde, Hat gezwickt
- "Ruhige Runde auf dem Rad, das tut den Beinen gut. Morgen der lange Lauf, heute früh ins Bett."

Antworte nur mit diesem JSON:
{"review": "…", "question": "… oder null", "options": ["…"]}
PROMPT;
    }

    /**
     * Adjust a single training session based on today's wellbeing data.
     * Returns updated session fields or null on failure.
     */
    /**
     * @param  array  $garmin  Tageswerte aus {@see \App\Services\GarminHealthSummary::forDay()}
     */
    public function adjustSessionForWellbeing(array $session, \App\Models\WellbeingEntry $wellbeing, array $garmin = []): ?array
    {
        $sick    = $wellbeing->is_sick    ? 'Ja' : 'Nein';
        $injured = $wellbeing->is_injured ? 'Ja' : 'Nein';
        $notes   = $wellbeing->notes ? "\n- Notiz des Athleten: \"" . mb_substr($wellbeing->notes, 0, 500) . '"' : '';

        // Die gemessenen Werte der Uhr. Sie liegen längst in der Datenbank und
        // fließen in die Planerstellung ein — nur die tägliche Anpassung sah
        // sie nicht. Der Plan-Prompt sagt „gemessene Erholung schlägt die
        // Selbsteinschätzung"; genau hier wurde danach nicht gehandelt.
        if (! empty($garmin['has_data'])) {
            $garminText = "\n\n**Gemessene Werte der Uhr (heute Nacht):**\n- "
                . implode("\n- ", $garmin['lines']);

            if (! empty($garmin['flags'])) {
                $garminText .= "\n\n⚠️ Auffällig: " . implode(', ', $garmin['flags']) . '.';
            }
        } else {
            $garminText = "\n\n**Gemessene Werte der Uhr:** keine vorhanden — entscheide allein nach der Selbsteinschätzung.";
        }

        // Den Ablauf baut der Katalog; das Modell waehlt nur den Baustein.
        $workouts = collect(\App\Services\Run\RunWorkouts::VARIANTS)
            ->map(fn ($v, $type) => $type . ': ' . implode(', ', $v))
            ->implode('; ');

        $prompt = <<<PROMPT
Du bist ein Lauf-Coach. Passe die folgende Trainingseinheit an den aktuellen Gesundheitszustand des Athleten an.

**Geplante Einheit:**
- Typ: {$session['type']}
- Titel: {$session['title']}
- Beschreibung: {$session['description']}
- Distanz: {$session['distance_km']} km
- Dauer: {$session['duration_min']} min
- Pace-Ziel: {$session['pace_target']}
- Zone: {$session['zone']}
- Intensität: {$session['intensity']}

**Aktuelles Wellbeing:**
- Energie: {$wellbeing->energy_level}/10
- Schlaf: {$wellbeing->sleep_quality}/10
- Muskelkater: {$wellbeing->muscle_soreness}/10
- Stress: {$wellbeing->stress_level}/10
- Krank: {$sick}
- Verletzt: {$injured}{$notes}{$garminText}

**Anpassungsregeln:**
- Krank oder verletzt → Typ "rest", Distanz 0, Dauer 0, Intensität "rest"
- Spricht die Notiz von OP, Krankheit, Verletzung oder Schmerzen → ebenfalls "rest"
- Energie ≤ 3 oder Schlaf ≤ 3 → Intensität auf "low" reduzieren, Distanz um 30-40% kürzen
- Muskelkater ≥ 7 → Typ zu "easy_run", Intensität "low", Pace 30-45 Sek langsamer
- Stress ≥ 8 → Dauer kürzen um 20%, Intensität reduzieren
- Sonst → leichte Anpassung der Beschreibung mit Hinweis auf Wellbeing

**Wenn die Uhr etwas anderes sagt als der Athlet:**
- Die gemessenen Werte wiegen schwerer als die Selbsteinschätzung. Wer sich gut fühlt, aber eine deutlich gefallene HRV, einen erhöhten Ruhepuls oder eine Readiness unter 35 hat, bekommt trotzdem die leichtere Einheit — die Uhr sieht die Erholung, das Gefühl sieht die Motivation.
- Umgekehrt gilt das nicht: Wer sich schlecht fühlt, bekommt die leichtere Einheit auch dann, wenn die Werte gut aussehen.
- Ein einzelnes Warnsignal → Intensität eine Stufe zurück, Umfang um 20 % kürzen. Zwei oder mehr → auf easy_run in Zone 2 zurückfallen.
- Kürze die Einheit, statt sie zu streichen. Nur bei Krankheit, Verletzung oder mehreren schweren Signalen wird daraus ein Ruhetag.
- Sage in der description in einem Halbsatz, WORAN die Anpassung liegt („Ruhepuls 8 % über der Grundlinie"). Der Athlet soll die Zahl sehen, nicht nur das Ergebnis.

Antworte ausschließlich mit JSON (kein anderer Text):
{
  "reason": "...",
  "type": "...",
  "title": "...",
  "description": "...",
  "distance_km": 0,
  "duration_min": 0,
  "pace_target": "... oder null",
  "zone": 1,
  "intensity": "...",
  "workout": "..."
}

`workout` ist der Baustein für den Ablauf — passend zum Typ ({$workouts}).
Abschnitte, Paces und Distanz baut das System daraus und aus duration_min;
schreibe deshalb keine Intervallstruktur in die description.

`reason` ist der Satz, mit dem du dem Athleten den Vorschlag begründest —
er entscheidet selbst, ob er ihn annimmt. HÖCHSTENS 140 Zeichen, ein Satz,
und er nennt den Grund, nicht die Massnahme: „Ruhepuls 8 % über deiner
Grundlinie und Schlaf 4/10" statt „Ich habe die Einheit gekürzt". Was du
änderst, sieht er daneben.
PROMPT;

        $text = $this->ai->chat('adjust_session', [
            ['role' => 'system', 'content' => 'Du bist ein präziser Lauf-Coach. Antworte ausschließlich mit validem JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.4, 1000, 30, $this->ai->mini());

        return $this->ai->jsonObject($text);
    }
}
