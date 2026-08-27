<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * Inhalte einer einzelnen Einheit: Schritteliste, Verpflegung und das
 * Coach-Review danach.
 */
class SessionContentService
{
    use TalksToOpenAI;

    /**
     * Generate personalised nutrition tips for a training session.
     * Returns array with keys: before[], during[], after[]
     */
    public function generateNutritionTips(array $session): ?array
    {
        $isRace = ($session['type'] ?? '') === 'race';

        $distKm  = (float) ($session['distance_km']  ?? 0);
        $durMin  = (int)   ($session['duration_min'] ?? 0);
        $type    = $session['type']      ?? 'easy_run';
        $intens  = $session['intensity'] ?? 'medium';
        $pace    = $session['pace_target'] ?? 'keine Angabe';

        // Strength / core / mobility sessions need a completely different protocol
        // (muscle protein synthesis, no race fueling) — handle them separately.
        if (! empty($session['is_strength'])) {
            return $this->generateStrengthNutritionTips($session);
        }

        $prompt = <<<PROMPT
Du bist Ernährungsberater für Leistungssportler. Erstelle präzise, athletengerechte Verpflegungstipps für die folgende Laufeinheit. Verzichte auf allgemeine Ratschläge — der Athlet kennt die Basics. Gib stattdessen konkrete Mengen, exaktes Timing und bewährte Sportprodukte.

**Einheit:**
- Typ: {$type}
- Distanz: {$distKm} km
- Dauer: {$durMin} min
- Pace-Ziel: {$pace}
- Intensität: {$intens}
- Renntag: {$session['is_race']}

**Protokoll nach Einheitstyp:**

Unter 45 min / lockeres Lauftempo:
- Vorher: Nüchtern oder 1-2h nach letzter Mahlzeit, kein extra Snack nötig
- Während: nur Wasser (0,5–1L je nach Hitze), keine Gels
- Nachher: Protein innerhalb 30 min (25–30g Whey oder 500ml fettarme Milch), Carbs in der Folgestunde

45–75 min / moderat:
- Vorher: 2–3h vorher kohlenhydratreiche Mahlzeit (Haferflocken + Beeren, Reis, Brot); 30–60 min vorher maximal 1 Energy-Gel oder 30g Datteln
- Während: alle 30 min 150–200ml Wasser, bei Hitze Elektrolyttablette (z.B. Nuun, SaltStick)
- Nachher: Recovery Shake (25g Whey + 50g Carbs) oder Quark + Obst

Über 75 min / langer Lauf / Renntag:
- Vorher: 3h vorher Pasta/Reis (80–100g trocken), 2h vorher nichts Festes mehr; 15 min vor Start 1 Gel (z.B. Maurten 160, SiS Beta Fuel)
- Während: Gel alle 40–45 min (z.B. GU Original, Maurten 100), Elektrolytgetränk oder Wasser mit Salztablette; ab 90 min isotonisches Getränk (400–600ml/h)
- Nachher: innerhalb 30 min Recovery Shake (4:1 Carb-Protein-Verhältnis), dann vollständige Mahlzeit nach 1–2h

Intervall / Tempo:
- Vorher: 3h vorher leichte kohlenhydratreiche Mahlzeit (kein Fett/Ballaststoffe); 30 min vorher optional Koffein (3–5mg/kg KG)
- Während: Wasser + Elektrolyte, bei >60 min ein Gel in der Pause
- Nachher: Proteinshake unmittelbar danach (30g Whey), innerhalb 2h vollständige Erholungsmahlzeit

Antworte ausschließlich mit JSON (kein anderer Text):
{
  "before": [{"icon": "🍝", "text": "..."}, ...],
  "during": [{"icon": "💧", "text": "..."}, ...],
  "after":  [{"icon": "🥩", "text": "..."}, ...]
}
Max. 3 Punkte pro Abschnitt. Konkrete Mengen, Produkte, Zeitangaben. Kein Allgemeinwissen. Alle Texte auf Deutsch.
PROMPT;

        $text = $this->ai->chat('nutrition', [
            ['role' => 'system', 'content' => 'Du bist ein Ernährungs- und Laufexperte. Antworte ausschließlich mit validem JSON. Alle Texte im JSON müssen auf Deutsch sein.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.5, 1500, 30, $this->ai->mini());

        return $this->ai->jsonObject($text);
    }

    /**
     * Nutrition tips tailored to strength / core / mobility sessions.
     * Focus: pre-workout fuel, intra-workout hydration, post-workout muscle recovery.
     */
    private function generateStrengthNutritionTips(array $session): ?array
    {
        $type   = $session['type']         ?? 'strength';
        $durMin = (int) ($session['duration_min'] ?? 0);
        $intens = $session['intensity']    ?? 'medium';

        $typeLabel = [
            'strength' => 'Krafttraining',
            'core'     => 'Core-/Rumpftraining',
            'mobility' => 'Mobility-/Beweglichkeitstraining',
        ][$type] ?? 'Krafttraining';

        // Compact list of the planned exercises so tips can reference the load.
        $exerciseLines = '';
        if (! empty($session['exercises']) && is_array($session['exercises'])) {
            $lines = [];
            foreach ($session['exercises'] as $ex) {
                $name = $ex['name'] ?? null;
                if (! $name) continue;
                $setsReps = trim(implode('×', array_filter([$ex['sets'] ?? null, $ex['reps'] ?? null])));
                $load     = ! empty($ex['load']) ? " @ {$ex['load']}" : '';
                $lines[]  = "- {$name}" . ($setsReps ? " ({$setsReps})" : '') . $load;
            }
            if ($lines) {
                $exerciseLines = "\n\n**Übungen:**\n" . implode("\n", $lines);
            }
        }

        $prompt = <<<PROMPT
Du bist Ernährungsberater für Kraft- und Athletiktraining von Ausdauersportlern. Erstelle präzise, athletengerechte Verpflegungstipps für die folgende Kraft-/Core-Einheit. KEINE Lauf-/Renn-Ernährung (keine Gels, keine Renn-Kohlenhydrate während der Einheit). Fokus: Energie für die Einheit, Hydration während der Einheit, Muskelregeneration (Proteinsynthese) danach. Verzichte auf Allgemeinplätze — gib konkrete Mengen, Timing und Produkte.

**Einheit:**
- Typ: {$typeLabel}
- Dauer: {$durMin} min
- Intensität: {$intens}{$exerciseLines}

**Protokoll für Kraft-/Core-Training:**

Vorher (vor dem Training):
- 1,5–2h vorher ausgewogene Mahlzeit mit Kohlenhydraten + Protein (z.B. Reis/Kartoffeln + Hähnchen/Tofu, oder Haferflocken + Skyr)
- Bei Low-Intervall/nüchtern: 1–2h nach einer normalen Mahlzeit starten reicht; alternativ kleiner Snack (Banane, Handvoll Nüsse)
- Optional 30 min vorher Koffein (3–5 mg/kg KG) für Maximalkraft

Während (während des Trainings):
- Hauptsächlich Wasser (150–300 ml über die Einheit), bei Hitze Elektrolyte
- KEINE Gels, kein Sportgetränk nötig bei <60 min
- Bei sehr langen/intensiven Einheiten: EAA/BCAA optional, aber nicht zwingend

Nachher (nach dem Training):
- Innerhalb von 30–60 min 25–35 g hochwertiges Protein (Whey-Shake, Skyr 300–400 g, oder 500 ml fettarme Milch) für die Muskelproteinsynthese
- Dazu Kohlenhydrate zur Glykogen-Auffüllung (30–60 g, z.B. Banane, Toast, Reis)
- Ausreichend trinken; ggf. 3 g Kreatin-Monohydrat als tägliche Routine

Antworte ausschließlich mit JSON (kein anderer Text):
{
  "before": [{"icon": "🍚", "text": "..."}, ...],
  "during": [{"icon": "💧", "text": "..."}, ...],
  "after":  [{"icon": "🥩", "text": "..."}, ...]
}
Max. 3 Punkte pro Abschnitt. Konkrete Mengen, Produkte, Zeitangaben. Kein Allgemeinwissen. Alle Texte auf Deutsch.
PROMPT;

        $text = $this->ai->chat('nutrition', [
            ['role' => 'system', 'content' => 'Du bist ein Ernährungsexperte für Kraft- und Athletiktraining. Antworte ausschließlich mit validem JSON. Alle Texte im JSON müssen auf Deutsch sein.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.5, 1500, 30, $this->ai->mini());

        return $this->ai->jsonObject($text);
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
            $ts = $profile->threshold_speed;
            $tPace = sprintf('%d:%02d', (int)$ts, (int)round(($ts - (int)$ts) * 60));
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
        ], 0.6, 1200, 60, $this->ai->mini());

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
     * Generate training plan using AI
     */
    public function generateTrainingPlan(array $goalData, array $progressData): string
    {
        $prompt = <<<PROMPT
Erstelle einen einfachen Trainingsplan für folgendes Ziel:

**Ziel:** {$goalData['name']} - {$goalData['target_value']} km
- Start: {$goalData['start_date']}
- Ende: {$goalData['end_date']}
- Aktueller Stand: {$progressData['completed_distance_km']} / {$goalData['target_value']} km

Gib einen kurzen, praktischen Wochenplan (3-4 Trainingstage) mit:
- Empfohlene Distanzen pro Tag
- Intensität (leicht/mittel/intensiv)
- Ruhetage
- Motivierende Tipps

Sei präzise und kurz!
PROMPT;

        $content = $this->ai->chat('plan', [
            ['role' => 'system', 'content' => 'Du bist ein erfahrener Lauf-Coach. Erstelle praktische, machbare Trainingspläne auf Deutsch.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.7, 1200, 30, $this->ai->mini());

        return $content ?? 'Trainingsplan konnte nicht erstellt werden.';
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
- Verletzt: {$injured}{$garminText}

**Anpassungsregeln:**
- Krank oder verletzt → Typ "rest", Distanz 0, Dauer 0, Intensität "rest"
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
  "type": "...",
  "title": "...",
  "description": "...",
  "distance_km": 0,
  "duration_min": 0,
  "pace_target": "... oder null",
  "zone": 1,
  "intensity": "..."
}
PROMPT;

        $text = $this->ai->chat('adjust_session', [
            ['role' => 'system', 'content' => 'Du bist ein präziser Lauf-Coach. Antworte ausschließlich mit validem JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.4, 1000, 30, $this->ai->mini());

        return $this->ai->jsonObject($text);
    }
}
