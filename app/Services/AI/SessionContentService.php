<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;
use App\Services\PaceFormat;

/**
 * Inhalte einer einzelnen Einheit: Schritteliste und das Coach-Review
 * danach. Die Verpflegungstipps sind am 29.10.2026 weggefallen (Jan).
 */
class SessionContentService
{
    use TalksToOpenAI;

    /**
     * Bis zu so vielen Minuten Abweichung gilt als Rundung und wird in der
     * Struktur ausgeglichen. Alles darueber ist ein echter Widerspruch
     * zwischen Beschreibung und Dauer — den verdeckt man nicht.
     */
    public const STEP_ROUNDING_TOLERANCE_MIN = 2;

    /** Die Gesamtdauer einer Schritteliste, Wiederholungen eingerechnet. */
    public static function stepsTotalMinutes(array $steps): int
    {
        return (int) array_sum(array_map(
            fn ($s) => (int) ($s['duration_min'] ?? 0) * max(1, (int) ($s['repetitions'] ?? 1)),
            $steps,
        ));
    }

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
     * Generate a structured step list for a planned training session.
     * Steps include warmup, work intervals (with repetitions), rest, and cooldown.
     * Returns array of step objects or null on failure.
     */
    public function generateSessionSteps(\App\Models\TrainingSession $session): ?array
    {
        // Backyard yard simulations follow an hourly rhythm (run one loop, then rest the
        // remainder of the hour) — not a warmup/interval/cooldown structure. They are built
        // deterministically so loop distance, pace and pauses stay consistent. The generic
        // interval prompt below produced nonsensical output here (e.g. a "1-min loop").
        // No warmup/cooldown — there is none in the race either.
        if ($session->type === 'yard_simulation') {
            return $this->buildYardSimulationSteps($session);
        }

        $typeLabel = [
            'interval'         => 'Intervalltraining',
            'tempo_run'        => 'Tempolauf',
            'easy_run'         => 'Lockerer Lauf',
            'long_run'         => 'Langer Lauf',
            'race_prep'        => 'Rennvorbereitung',
            'progressive_run'  => 'Progressiver Lauf',
            'test_run'         => 'Testlauf (Zeitversuch)',
            'back_to_back_long'=> 'Back-to-Back Longrun',
            'time_on_feet'     => 'Time on Feet (sehr locker)',
            'night_run'        => 'Nachtlauf (locker)',
        ][$session->type] ?? $session->type;

        $totalMin    = (int)($session->duration_min ?? 0);
        $distKm      = $session->distance_km ? "{$session->distance_km} km" : 'nicht angegeben';
        $pace        = ($session->pace_target && $session->pace_target !== 'null') ? "{$session->pace_target} min/km" : null;
        $zone        = $session->zone ? "Zone {$session->zone}" : null;
        $desc        = $session->description ? "\nBeschreibung: {$session->description}" : '';

        // Provide threshold pace so AI can calculate zone-specific paces
        $profile       = $session->user?->runnerProfile;
        $thresholdLine = '';
        if ($profile?->threshold_speed) {
            $tPace = PaceFormat::fromMinutes($profile->threshold_speed);
            $thresholdLine = "\nSchwellenpace des Athleten: {$tPace} min/km";
        }

        // Proportional warmup/cooldown budgets
        $warmupMin   = $totalMin > 0 ? max(3, (int)round($totalMin * 0.15)) : 5;
        $cooldownMin = $totalMin > 0 ? max(3, (int)round($totalMin * 0.12)) : 5;
        $mainMin     = $totalMin > 0 ? $totalMin - $warmupMin - $cooldownMin : 0;

        $durationRule = $totalMin > 0
            ? "ZEITBUDGET: Gesamtdauer = {$totalMin} Minuten.\n" .
              "WICHTIG: Nennt die Beschreibung konkrete Intervall-Dauern oder -Wiederholungen " .
              "(z.B. \"3×12 Min bei 4:58-5:05 mit 6 Min locker dazwischen\"), MUSST du EXAKT diese Werte übernehmen " .
              "(work duration_min = 12, repetitions = 3, rest duration_min = 6). Verändere sie NIEMALS, um das Zeitbudget zu füllen!\n" .
              "Die verbleibende Zeit ist lockeres Dauerlaufen und wird über längeres Ein-/Auslaufen verteilt " .
              "— NICHT indem du die harten Intervalle streckst.\n" .
              "Empfehlung (nur wenn die Beschreibung KEINE konkreten Dauern nennt): " .
              "Aufwärmen ~{$warmupMin} min, Hauptteil ~{$mainMin} min gesamt, Auslaufen ~{$cooldownMin} min."
            : "Wähle eine sinnvolle Gesamtdauer.";

        $prompt = <<<PROMPT
Du bist ein präziser Lauf-Coach. Erstelle eine strukturierte Workout-Schritteliste.

Einheit: {$typeLabel} – {$session->title}
Distanz: {$distKm} | Dauer: {$totalMin} min | Pace-Ziel: {$pace} | {$zone}{$desc}{$thresholdLine}

{$durationRule}

Regeln:
- Konkrete Dauern/Wiederholungen aus der Beschreibung haben IMMER Vorrang vor dem Zeitbudget
- warmup + cooldown immer enthalten
- duration_min: NUR positive GANZE ZAHLEN (kein 0.33, kein 1.5 – nur 1, 2, 3 …)
- Intervalle: work + rest Steps mit gleichem "repetitions"-Wert
- work-Steps: pace_target MUSS konkrete Pace "M:SS" enthalten (nie null oder "locker")
- rest-Steps: pace_target = null, zone = 1
- Easy/Tempo-Läufe: ein work-Step ohne repetitions (null)
- Maximal 6 Schritte total
- Progressiver Lauf: warmup Z1, dann 2–3 work-Steps steigend (Z2→Z3→Z4)
- Testlauf: ausgiebiges warmup + Strides, dann 1 work-Step Zeitversuch (Z4–5)

Antworte NUR mit JSON-Array:
[
  {"type": "warmup",   "label": "Einlaufen",  "duration_min": 5,  "pace_target": "6:00", "zone": 1, "repetitions": null},
  {"type": "work",     "label": "Intervall",  "duration_min": 3,  "pace_target": "4:10", "zone": 4, "repetitions": 4},
  {"type": "rest",     "label": "Trabpause",  "duration_min": 1,  "pace_target": null,   "zone": 1, "repetitions": 4},
  {"type": "cooldown", "label": "Auslaufen",  "duration_min": 5,  "pace_target": "6:30", "zone": 1, "repetitions": null}
]
PROMPT;

        $text = $this->ai->chat('session_steps', [
            ['role' => 'system', 'content' => 'Antworte ausschließlich mit validem JSON-Array ohne Text. duration_min sind ganze Zahlen ≥ 1.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.3, 1200, 45, $this->ai->mini());

        $steps = $this->ai->jsonArray($text);

        return $steps ? $this->normalizeStepDurations($steps, $totalMin ?: null) : null;
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
    private function buildYardSimulationSteps(\App\Models\TrainingSession $session): array
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

    /** Round all step durations to integers and adjust the largest work step so the total matches target. */

    private function normalizeStepDurations(array $steps, ?int $targetMin): array
    {
        foreach ($steps as &$step) {
            $step['duration_min'] = max(1, (int)round($step['duration_min'] ?? 1));
        }
        unset($step);

        if (!$targetMin) return $steps;

        $total = array_sum(array_map(
            fn ($s) => $s['duration_min'] * max(1, (int)($s['repetitions'] ?? 1)),
            $steps
        ));

        if ($total === $targetMin) return $steps;

        $diff = $targetMin - $total;

        // Nur Rundungsreste ausgleichen.
        //
        // Vorher wurde JEDE Differenz hier hineingedrueckt — und zwar ins
        // Auslaufen. Wer in der Beschreibung "danach 10 min auslaufen" las
        // und in der Struktur 7 fand, sah genau das: die Beschreibung
        // beschrieb 58 Minuten, die Einheit war auf 55 gesetzt, und die drei
        // fehlenden Minuten wurden dem Auslaufen abgezogen.
        //
        // Die Schritte folgen der Beschreibung — das ist ihr Zweck. Weicht
        // die Summe staerker ab, ist nicht die Struktur falsch, sondern die
        // Dauer der Einheit. Der Aufrufer zieht sie nach.
        if (abs($diff) > self::STEP_ROUNDING_TOLERANCE_MIN) {
            return $steps;
        }

        // Absorb the leftover time in the EASY/continuous portion — never by
        // stretching a repeated work interval. Its per-rep duration is
        // prescribed by the coach (e.g. 3×12 min MP); inflating it to hit the
        // budget corrupts the workout (12 min → 26 min). The extra minutes of a
        // long run are easy filler and belong in warmup/cooldown/continuous work.
        $isRepeatedWork = fn ($s) => ($s['type'] ?? '') === 'work' && (int)($s['repetitions'] ?? 1) > 1;

        $adjustIdx = null;

        // 1) A continuous work step (tempo/easy/long single effort) absorbs first.
        foreach ($steps as $i => $step) {
            if (($step['type'] ?? '') === 'work' && !$isRepeatedWork($step)) { $adjustIdx = $i; break; }
        }
        // 2) Otherwise the easy filler around intervals: cooldown, then warmup.
        if ($adjustIdx === null) {
            foreach (['cooldown', 'warmup'] as $wantType) {
                foreach ($steps as $i => $step) {
                    if (($step['type'] ?? '') === $wantType) { $adjustIdx = $i; break 2; }
                }
            }
        }
        // 3) Last resort (only intervals, no easy step): highest-contribution work step.
        if ($adjustIdx === null) {
            $maxContrib = 0;
            foreach ($steps as $i => $step) {
                if (($step['type'] ?? '') === 'rest') continue;
                $contrib = $step['duration_min'] * max(1, (int)($step['repetitions'] ?? 1));
                if ($contrib > $maxContrib) { $maxContrib = $contrib; $adjustIdx = $i; }
            }
        }

        if ($adjustIdx !== null) {
            $reps = max(1, (int)($steps[$adjustIdx]['repetitions'] ?? 1));
            $steps[$adjustIdx]['duration_min'] = max(1, $steps[$adjustIdx]['duration_min'] + (int)round($diff / $reps));
        }

        return $steps;
    }

    /**
     * Post-session coach review from the real data of a completed session.
     * The job assembles $factsBlock (actual metrics, baseline comparison, aerobic
     * decoupling, wellbeing, training load, weather, athlete rating). The coach
     * writes a short personal review, highlights anything unusual (e.g. HR higher
     * than usual) with a plausible cause, and — when a deviation has several
     * possible reasons — asks ONE follow-up question with tap-answer options.
     *
     * @return array{review:string, question:?string, options:array<string>}|null
     */
    public function generateSessionReview(\App\Models\User $user, string $sessionLabel, string $factsBlock): ?array
    {
        $prompt = <<<PROMPT
Der Athlet hat gerade eine Trainingseinheit absolviert. Analysiere sie ausschließlich auf Basis der ECHTEN Daten unten und schreibe ein kurzes, persönliches Review.

Absolvierte Einheit: {$sessionLabel}

**Daten:**
{$factsBlock}

Aufgabe:
- Steht in den Daten eine Zeile "SPORTART", war das KEIN Lauf. Sprich ueber genau diese Sportart und benutze ihre Sprache — bei Schwimmen Bahnen und Technik, beim Radfahren Watt, Trittfrequenz und Dauer. Bewerte dort NIEMALS Pace in min/km, Laufzonen oder die Schwellenpace, und frage auch nicht nach dem Lauf. Ordne die Einheit als Ergaenzung zum Lauftraining ein: was sie dem Athleten bringt und ob sie neben dem Laufpensum sinnvoll liegt.
- Beginne mit der EINORDNUNG aus den Daten:
  · Stand die Einheit im Plan und wurde sie im Rahmen umgesetzt, sag das kurz und geh weiter.
  · Steht dort eine "Abweichung vom Plan", benenne sie mit den Zahlen und ordne sie ein — deutlich länger oder schneller als vorgesehen ist nicht automatisch gut, sondern kostet Erholung und kann die nächste Schlüsseleinheit gefährden. Zu kurz oder zu langsam ist ebenfalls kein Drama, wenn der Kontext (Schlaf, Stress, Belastung) es erklärt.
  · Ist die Einheit als UNGEPLANT ausgewiesen, sag das ausdrücklich und bewerte sie als Zusatzbelastung: passt sie zum Trainingsstand, oder geht sie auf Kosten der nächsten geplanten Einheit? War der Tag als RUHETAG vorgesehen, benenne das offen.
- 2–4 Sätze Review in direkter Ansprache (du). Konkret mit Zahlen aus den Daten, ehrlich und motivierend — niemals generisch.
- Hebe AUFFÄLLIGKEITEN hervor (z.B. Puls deutlich höher/niedriger als sonst, ungewöhnliche Pace, starke aerobe Entkopplung/Puls-Drift) und nenne eine plausible Ursache aus den Kontextdaten (Schlaf, Stress, Wetter, Ermüdung/Belastung).
- Stehen Zeilen zu "Wochenumfang", "Umsetzung der letzten 4 Wochen" oder "Entwicklung bei …" in den Daten, ordne die Einheit darin ein — EIN Satz, nicht mehr. Derselbe Lauf bedeutet etwas anderes in einer Woche mit halbem Umfang als im dritten harten Block, und "gleicher Puls, aber schneller als vor zwei Monaten" ist die Aussage, auf die ein Athlet wochenlang hinarbeitet. Übernimm die Zahlen wie sie dastehen und rechne nichts dazu. Ändert der Verlauf nichts an der Einordnung, lass ihn weg statt ihn zu referieren.
- Hat eine Auffälligkeit MEHRERE plausible Ursachen, stelle dem Athleten EINE kurze, konkrete Rückfrage ("question") und biete 2–4 knappe Antwortoptionen ("options") an (z.B. "Schlecht geschlafen", "Wetter", "Müde Beine", "Alles normal").
- Gibt es nichts Auffälliges, setze "question" auf null und "options" auf [].

Antworte AUSSCHLIESSLICH mit einem JSON-Objekt, alle Texte auf Deutsch:
{"review": "…", "question": "… oder null", "options": ["…", "…"]}
PROMPT;

        $this->forUser($user->id);

        $text = $this->ai->chat('session_review', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Antworte ausschließlich mit dem angeforderten JSON-Objekt. Alle Texte auf Deutsch.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.6, 1200, 60, $this->ai->mini(), background: true);

        $data = $this->ai->jsonObject($text);
        if ($data !== null) {
            if (! empty($data['review'])) {
                $question = ! empty($data['question']) && $data['question'] !== 'null' ? trim($data['question']) : null;
                $options  = (! empty($data['options']) && is_array($data['options']))
                    ? array_values(array_filter(array_map('trim', $data['options'])))
                    : [];
                // A question without options (or vice versa) is not a usable dialog — drop both.
                if (! $question || empty($options)) {
                    $question = null;
                    $options  = [];
                }
                return ['review' => trim($data['review']), 'question' => $question, 'options' => $options];
            }
        }

        return null;
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
  "intensity": "..."
}

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
