<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * Werte, die den Athleten beschreiben: Schwellenpace, Pace-Zonen und die
 * Erstschaetzung des Profils aus einer genannten Wettkampfzeit.
 */
class AthleteProfileService
{
    use TalksToOpenAI;

    /** Unterhalb dieser Dauer ist eine Runde kein Belastungsintervall, sondern eine Steigerung. */
    private const WORK_LAP_MIN_SECONDS = 90;

    /** Nur so viele Einheiten bekommen ihre Intervalle mitgeliefert. */
    private const MAX_DETAILED = 8;

    /** Plausible Grenzen einer Schwellenpace in Minuten je Kilometer. */
    private const PACE_MIN = 2.5;
    private const PACE_MAX = 12.0;

    /**
     * Schätzt die Schwellenpace (LT2) aus den letzten Läufen.
     *
     * Was hier NICHT passiert: die Herzfrequenz als Filter benutzen. Der
     * Ø-Puls einer Intervalleinheit liegt strukturell unter der LTHR, weil
     * Einlaufen und Trabpausen mitgemittelt werden — genau die wertvollste
     * Einheit sähe damit am unwichtigsten aus. Stattdessen bekommt das Modell
     * die einzelnen Belastungsintervalle, in denen Pace und Puls tatsächlich
     * etwas über die Schwelle aussagen.
     *
     * @param  array<int,array<string,mixed>>  $activities  Vollständige Aktivitätszeilen inkl. laps.
     * @return array{pace: float, range: ?string, confidence: string, evidence: list<string>}|null
     */
    public function calculateThresholdPaceWithAI(array $activities, ?int $lthr = null): ?array
    {
        if (empty($activities)) {
            return null;
        }

        $lines    = [];
        $detailed = 0;

        foreach ($activities as $activity) {
            if (($activity['average_speed'] ?? 0) <= 0 || ($activity['distance'] ?? 0) < 3000) {
                continue;
            }

            $distanceKm  = $activity['distance'] / 1000;
            $durationMin = ($activity['moving_time'] ?? 0) / 60;
            $avgHr       = $activity['average_heartrate'] ?? null;
            $maxHr       = $activity['max_heartrate'] ?? null;

            $hrBits = [];
            if ($avgHr) $hrBits[] = 'Ø-Puls ' . (int) $avgHr . ' bpm';
            if ($maxHr) $hrBits[] = 'Max ' . (int) $maxHr . ' bpm';

            $line = sprintf(
                '- [%s] %s: %.2f km, %.0f min, Ø-Pace %s min/km%s',
                $this->activityDate($activity),
                $activity['name'] ?? 'Lauf',
                $distanceKm,
                $durationMin,
                PaceFormat::fromSpeed($activity['average_speed']),
                $hrBits ? ', ' . implode(', ', $hrBits) : ', keine Pulsdaten',
            );

            // Die Belastungsintervalle. Ohne sie sieht das Modell von einer
            // Einheit "4x 8 Min Schwelle" nur einen Mischwert aus Intervallen,
            // Trabpausen und Einlaufen — und damit eine Pace, die niemand
            // gelaufen ist.
            if ($detailed < self::MAX_DETAILED) {
                $work = $this->workIntervals($activity);
                if ($work !== []) {
                    $line .= "\n    Belastungsintervalle: " . implode(' | ', $work);
                    $detailed++;
                }
            }

            $lines[] = $line;
        }

        if (empty($lines)) {
            return null;
        }

        $activitiesText = implode("\n", $lines);

        // Die LTHR ist selbst eine Schaetzung — sie stand hier als Tatsache,
        // und jede Abweichung davon wurde dem Athleten angelastet statt dem
        // Referenzwert.
        $lthrText = $lthr
            ? "Hinterlegte LTHR: {$lthr} bpm. Das ist ein geschätzter Richtwert, kein gemessener. "
                . "Passt er zu keiner Einheit in der Liste, ist eher der Wert zu hoch angesetzt "
                . "als der Athlet zu langsam — sage das dann in der Begründung."
            : 'Keine LTHR hinterlegt.';

        $prompt = <<<PROMPT
Du bist Sportwissenschaftler mit Schwerpunkt Laktatschwellen-Diagnostik.

**Aufgabe:** Schätze die Schwellenpace (LT2) dieses Athleten.

**Definition:** Die Laufgeschwindigkeit an der oberen Schwelle — eine hohe, kontrollierte
Belastung, die je nach Athlet etwa 30 bis 60 Minuten haltbar ist. Die Dauer ist
individuell und keine feste Regel.

**Herzfrequenz richtig lesen:**
{$lthrText}
- Die HF ist ein Hinweis, kein Filter. Eine Einheit unterhalb der LTHR kann sehr wohl
  Schwellenarbeit gewesen sein.
- Der Ø-Puls einer ganzen Einheit ist bei Intervalltraining wertlos: Einlaufen,
  Trabpausen und Auslaufen ziehen ihn nach unten. Vergleiche ihn NIE direkt mit der LTHR.
- Wo "Belastungsintervalle" angegeben sind, zählen ausschließlich diese Werte.
- Cardiac Drift, Hitze, Ermüdung, Koffein und optische Messfehler verfälschen die HF.

**Gewichtung der Einheiten:**
1. Am wichtigsten: gezielte Schwellenintervalle, Tempoläufe, 20–60 min zusammenhängend
   hart, Wettkämpfe zwischen 5 km und Halbmarathon.
2. Wichtig: progressive Läufe, längere Intervalle ab 5 min.
3. Mittel: zügige Dauerläufe.
4. Kaum: lockere Dauerläufe, Recovery.
5. Gar nicht: Ultras, Backyards und sehr lange langsame Läufe. Deren Pace sagt über die
   Schwelle nichts aus — rechne sie AUF KEINEN FALL hoch.

**Wettkämpfe** sind starke Ankerpunkte. Ein Halbmarathon liegt meist etwas unter der
Schwellenpace, aber der Abstand ist individuell — benutze keine feste Prozentregel.

**Zeitliche Nähe** zählt, aber nach einem Ultra oder Marathon ist die Leistung
vorübergehend gedrückt; werte solche Wochen nicht als Rückschritt.

**Wenn die Daten es nicht hergeben:** erfinde keine Genauigkeit. Gib den plausibelsten
Bereich an und setze "confidence" ehrlich. Ein fehlender Anker im Bereich von 30–60
Minuten nahe der Schwelle bedeutet "low".

Keine einfache Durchschnittsbildung, keine lineare HF-zu-Pace-Umrechnung, keine
Schätzung aus einer einzigen Einheit.

**Aktivitäten (neueste zuerst):**
{$activitiesText}

Antworte ausschließlich mit diesem JSON:
{"threshold_pace":"M:SS","range":"M:SS-M:SS","confidence":"high|medium|low","evidence":["kurze Begründung","..."]}
PROMPT;

        $text = $this->ai->chat('threshold_pace', [
            ['role' => 'system', 'content' => 'Du bist ein präziser Sportwissenschaftler. Antworte ausschließlich mit JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.1, 3000);

        $json = $this->ai->jsonObject($text);
        $pace = isset($json['threshold_pace']) ? $this->paceStringToFloat((string) $json['threshold_pace']) : null;

        if ($pace === null) {
            Log::warning('Threshold pace AI parse failed', ['text' => $text]);
            return null;
        }

        // Eine Zahl ausserhalb jeder Plausibilitaet ist keine Schaetzung,
        // sondern ein Fehler — und sie wuerde alle Pace-Zonen mitreissen.
        if ($pace < self::PACE_MIN || $pace > self::PACE_MAX) {
            Log::warning('Threshold pace outside plausible range', ['pace' => $pace]);
            return null;
        }

        $confidence = strtolower((string) ($json['confidence'] ?? 'medium'));
        if (! in_array($confidence, ['high', 'medium', 'low'], true)) {
            $confidence = 'medium';
        }

        $evidence = is_array($json['evidence'] ?? null)
            ? array_values(array_filter(array_map('trim', array_map('strval', $json['evidence']))))
            : [];

        Log::info('Threshold pace calculated', [
            'lthr'       => $lthr,
            'pace'       => $json['threshold_pace'],
            'confidence' => $confidence,
            'activities' => count($lines),
            'detailed'   => $detailed,
        ]);

        return [
            'pace'       => $pace,
            'range'      => ! empty($json['range']) ? (string) $json['range'] : null,
            'confidence' => $confidence,
            'evidence'   => array_slice($evidence, 0, 4),
        ];
    }

    /**
     * Die Belastungsintervalle einer Einheit.
     *
     * Als Belastung gilt eine Runde, die schneller lief als die Einheit im
     * Schnitt und lang genug war, um etwas auszusagen. Ein Dauerlauf ohne
     * Struktur liefert damit nichts — richtig so, dort ist der Mittelwert
     * bereits die ganze Wahrheit.
     *
     * @return list<string>
     */
    private function workIntervals(array $activity): array
    {
        $laps = $activity['laps'] ?? null;
        if (! is_array($laps) || count($laps) < 3) {
            return [];
        }

        $avgSecPerKm = 1000 / $activity['average_speed'];
        $work        = [];

        foreach ($laps as $lap) {
            $meters  = (float) ($lap['distance'] ?? 0);
            $seconds = (int) ($lap['moving_time'] ?? $lap['elapsed_time'] ?? 0);

            if ($meters <= 0 || $seconds < self::WORK_LAP_MIN_SECONDS) {
                continue;
            }

            $secPerKm = $seconds / ($meters / 1000);

            // Drei Prozent Vorsprung auf den Schnitt der Einheit: genug, um
            // Trabpausen und Auslaufen auszuschliessen, wenig genug, um einen
            // gleichmaessigen Tempolauf noch als Belastung zu erkennen.
            if ($secPerKm > $avgSecPerKm * 0.97) {
                continue;
            }

            $entry = sprintf('%.0f min @ %s', $seconds / 60, $this->secondsToPace($secPerKm));
            if (! empty($lap['average_heartrate'])) {
                $entry .= ' (' . (int) $lap['average_heartrate'] . ' bpm)';
            }

            $work[] = $entry;
        }

        // Ist alles schneller als der Schnitt, war es kein Intervalltraining,
        // sondern eine Messeigenheit — dann lieber nichts sagen.
        return count($work) >= 2 && count($work) <= 20 ? $work : [];
    }

    private function activityDate(array $activity): string
    {
        $raw = $activity['start_date'] ?? null;
        if (! $raw) {
            return '—';
        }

        return is_string($raw) ? substr($raw, 0, 10) : date('Y-m-d', strtotime((string) $raw));
    }

    private function secondsToPace(float $seconds): string
    {
        $total = (int) round($seconds);

        return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
    }

    /**
     * Convert "M:SS" pace string to float minutes (e.g. "5:23" → 5.3833...)
     */
    protected function paceStringToFloat(string $pace): ?float
    {
        if (!preg_match('/^(\d+):(\d{2})$/', trim($pace), $m)) {
            return null;
        }
        return (int)$m[1] + (int)$m[2] / 60;
    }

    /**
     * Calculate pace zones via ChatGPT prompt
     */
    public function calculatePaceZonesWithAI(string $thresholdSpeed): array
    {
        try {
            $prompt = <<<PROMPT
Du bist ein erfahrener Lauf-Coach und sollst genaue Laufzonen für eine Schwellen-Pace berechnen.
Gib ausschließlich ein gültiges JSON-Objekt (ohne zusätzlichen Text) mit diesem Format:
{
  "pace_zones": {
    "z1": {"name":"...", "min_pace":"m:ss", "max_pace":"m:ss"},
    "z2": {...},
    "z3": {...},
    "z4": {...},
    "z5": {...}
  }
}

Eingabe:
- Schwellen-Pace: {$thresholdSpeed} (min:sek/km)

Nutze diese Regeln (nach Screenshot):
- Zone 1 (Recovery): > Schwellenpace + 1:21
- Zone 2 (Easy): Schwellenpace + 0:46 bis +1:21
- Zone 3 (Tempo): Schwellenpace + 0:21 bis +0:46
- Zone 4 (Threshold): Schwellenpace bis +0:21
- Zone 5 (VO2Max): unter Schwellenpace - 0:04
PROMPT;

            $text = $this->ai->chat('pace_zones', [
                ['role' => 'system', 'content' => 'Du bist ein präziser Lauf-Coach. Antworte nur mit JSON.'],
                ['role' => 'user',   'content' => $prompt],
            ], 0.2, 1000, 30, $this->ai->mini());

            $json = $this->ai->jsonObject($text);
            if (isset($json['pace_zones'])) {
                return $json['pace_zones'];
            }

            Log::warning('OpenAI Zone Calculation parse failed', ['text' => $text]);
            return [];

        } catch (\Exception $e) {
            Log::error('OpenAI Zone Calculation Exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Generate a structured training recommendation for today.
     * Returns an associative array with keys: type, title, description,
     * distance_km, duration_min, pace_target, zone, intensity.
     * Returns null on failure.
     */

    /**
     * Estimate runner profile (LTHR, Max HR, threshold pace) from simple user inputs.
     * Used in onboarding when the user doesn't know their exact training values.
     *
     * @param int    $age           Age in years
     * @param string $raceDistance  One of: 5km, 10km, half_marathon, marathon
     * @param string $raceTime      Best recent race time in H:MM:SS or MM:SS format
     * @param int    $weeklyRuns    Approximate runs per week (1-7)
     * @return array{threshold_heart_rate:int, max_heart_rate:int, threshold_speed:string}|null
     */
    public function estimateProfileFromRaceData(int $age, string $raceDistance, string $raceTime, int $weeklyRuns): ?array
    {
        $distanceLabels = [
            '5km'           => '5 km',
            '10km'          => '10 km',
            'half_marathon' => 'Halbmarathon (21,1 km)',
            'marathon'      => 'Marathon (42,2 km)',
        ];
        $distanceLabel = $distanceLabels[$raceDistance] ?? $raceDistance;

        $prompt = <<<PROMPT
Du bist Sportwissenschaftler und Lauf-Coach spezialisiert auf Leistungsdiagnostik.

Schätze basierend auf folgenden Angaben die Trainingskennwerte:
- Alter: {$age} Jahre
- Beste Wettkampfzeit über {$distanceLabel}: {$raceTime}
- Trainingsläufe pro Woche: {$weeklyRuns}

Berechne:
1. **Maximale Herzfrequenz (Max HR)**: Nutze die Formel 208 - (0.7 × Alter) als Basis, passe für Fitnessniveau an.
2. **Schwellenherzfrequenz (LTHR)**: Ca. 85-92% der Max HR abhängig von Erfahrung und Trainingsumfang.
3. **Schwellenpace (Threshold Pace)**: Die Pace, die ~60 min maximal gehalten werden kann. Leite sie aus der Wettkampfzeit via Jack-Daniels-VDOT-Äquivalent ab. 5km-Pace × 1.06 ≈ 10km, × 1.13 ≈ Halbmarathon-Renntempo. Schwellenpace liegt ca. 3-6% schneller als Halbmarathon-Renntempo.

Gib ausschließlich dieses JSON zurück (kein anderer Text):
{"threshold_heart_rate": <integer bpm>, "max_heart_rate": <integer bpm>, "threshold_speed": "<M:SS>"}
PROMPT;

        $text = $this->ai->chat('profile_estimation', [
            ['role' => 'system', 'content' => 'Du bist ein präziser Sportwissenschaftler. Antworte ausschließlich mit validem JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.2, 1000, 60, $this->ai->mini());

        $json = $this->ai->jsonObject($text);
        if ($json !== null) {
            if (isset($json['threshold_heart_rate'], $json['max_heart_rate'], $json['threshold_speed'])) {
                return [
                    'threshold_heart_rate' => (int) $json['threshold_heart_rate'],
                    'max_heart_rate'        => (int) $json['max_heart_rate'],
                    'threshold_speed'       => (string) $json['threshold_speed'],
                ];
            }
        }

        Log::warning('OpenAI Profile Estimation parse failed', ['text' => $text]);
        return null;
    }
}
