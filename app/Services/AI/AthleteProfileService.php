<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * Werte, die den Athleten beschreiben: Schwellenpace, Pace-Zonen und die
 * Erstschaetzung des Profils aus einer genannten Wettkampfzeit.
 */
class AthleteProfileService
{
    public function __construct(private readonly OpenAIClient $ai) {}

    /** Coach-Persoenlichkeit und Nutzer an den Transport durchreichen. */
    public function withCoach(?string $personalityPrompt): static
    {
        $this->ai->withCoach($personalityPrompt);

        return $this;
    }

    public function forUser(?int $userId): static
    {
        $this->ai->forUser($userId);

        return $this;
    }

    /**
     * Calculate threshold pace (Schwellenpace) from last 10 activities using AI.
     *
     * Algorithm:
     * - Categorizes runs by duration: threshold-range (35-75 min) = highest relevance,
     *   tempo runs (20-35 min) = high relevance (slightly faster than threshold, corrected),
     *   long easy runs (>100 min) = minimal relevance (almost always easy pace).
     * - Combines recency weight × category relevance for a total weight per activity.
     * - Pre-calculates a mathematical estimate from only the relevant activities.
     * - Passes structured data + estimate to AI for final refinement.
     *
     * Returns threshold pace as float minutes (e.g. 4.183 = 4:11 min/km), or null on failure.
     *
     * When $lthr is provided, HR-proximity to LTHR becomes the primary relevance signal:
     *   - avg_hr within ±5 bpm of LTHR  → direct threshold effort  (weight ×5.0)
     *   - avg_hr within ±10 bpm of LTHR → near-threshold            (weight ×3.5)
     *   - avg_hr >10 bpm below LTHR     → easy/recovery run         (weight ×0.15)
     *   - avg_hr >5 bpm above LTHR      → VO2max / race effort      (weight ×1.0, pace slightly too fast)
     * Duration-based weighting is combined additively when HR data is missing.
     */
    public function calculateThresholdPaceWithAI(array $activities, ?int $lthr = null): ?float
    {
        if (empty($activities)) {
            return null;
        }

        $count       = count($activities);
        $processed   = [];
        $hasAnyHR    = false;

        foreach ($activities as $index => $activity) {
            if (($activity['average_speed'] ?? 0) <= 0 || ($activity['distance'] ?? 0) < 3000) {
                continue;
            }

            $paceSecPerKm = 1000 / $activity['average_speed'];
            $durationMin  = ($activity['moving_time'] ?? 0) / 60;
            $distanceKm   = $activity['distance'] / 1000;
            $recency      = $count - $index;
            $avgHR        = $activity['average_heartrate'] ?? null;

            if ($avgHR) {
                $hasAnyHR = true;
            }

            // ── HR-based relevance (primary when LTHR + HR data are available) ──
            $hrRelevance  = null;
            $hrDiff       = null;
            $hrCategory   = 'Kein HF-Daten';
            $hrNote       = '';

            if ($lthr && $avgHR) {
                $hrDiff = $avgHR - $lthr;

                if (abs($hrDiff) <= 5) {
                    $hrRelevance = 5.0;
                    $hrCategory  = 'DIREKT an Schwelle (±5 bpm)';
                    $hrNote      = 'Pace = Schwellenpace';
                } elseif (abs($hrDiff) <= 10) {
                    $hrRelevance = 3.5;
                    $hrCategory  = 'Nahe Schwelle (±10 bpm)';
                    $hrNote      = $hrDiff > 0 ? 'Leicht über Schwelle → Pace etwas schneller' : 'Leicht unter Schwelle → Pace etwas langsamer';
                } elseif ($hrDiff < -10) {
                    $hrRelevance = 0.15;
                    $hrCategory  = 'Easy/Recovery (>' . abs($hrDiff) . ' bpm unter LTHR)';
                    $hrNote      = 'Easy-Pace — ignorieren';
                } else {
                    // HR above LTHR: race/VO2max effort — pace too fast for threshold
                    $hrRelevance = 1.0;
                    $hrCategory  = 'Über Schwelle (+' . $hrDiff . ' bpm)';
                    $hrNote      = 'VO2max/Race — Pace etwas schneller als Schwelle';
                }
            }

            // ── Duration-based relevance (fallback or complement) ──
            if ($durationMin >= 35 && $durationMin <= 75) {
                $durationRelevance = 3.0;
                $durationCategory  = 'Schwellenlauf-Bereich (35-75 min)';
            } elseif ($durationMin >= 20 && $durationMin < 35) {
                $durationRelevance = 2.0;
                $durationCategory  = 'Tempolauf (20-35 min)';
            } elseif ($durationMin > 75 && $durationMin <= 100) {
                $durationRelevance = 0.7;
                $durationCategory  = 'Mittellanger Lauf (75-100 min)';
            } elseif ($durationMin > 100) {
                $durationRelevance = 0.1;
                $durationCategory  = 'Langer Easy-Lauf (>100 min)';
            } else {
                $durationRelevance = 0.3;
                $durationCategory  = 'Kurzer Lauf (<20 min)';
            }

            // When HR data is available for this activity, HR-relevance leads.
            // When HR is missing, fall back to duration-relevance.
            $finalRelevance = $hrRelevance !== null
                ? max($hrRelevance, $durationRelevance * 0.3) // HR primary + small duration bonus
                : $durationRelevance;

            $totalWeight = round($recency * $finalRelevance, 2);
            $date = isset($activity['start_date'])
                ? (is_string($activity['start_date'])
                    ? substr($activity['start_date'], 0, 10)
                    : date('Y-m-d', strtotime($activity['start_date'])))
                : '—';

            $processed[] = [
                'date'              => $date,
                'name'              => $activity['name'],
                'distance_km'       => round($distanceKm, 2),
                'duration_min'      => round($durationMin, 1),
                'pace'              => PaceFormat::fromSpeed($activity['average_speed']),
                'pace_sec'          => $paceSecPerKm,
                'avg_hr'            => $avgHR ? (int)$avgHR : null,
                'hr_diff_to_lthr'   => $hrDiff !== null ? (int)$hrDiff : null,
                'hr_category'       => $hrRelevance !== null ? $hrCategory : $durationCategory,
                'hr_note'           => $hrNote ?: ($durationRelevance >= 2.0 ? 'Relevante Dauer' : 'Wenig relevant'),
                'recency'           => $recency,
                'final_relevance'   => $finalRelevance,
                'total_weight'      => $totalWeight,
            ];
        }

        if (empty($processed)) {
            return null;
        }

        // ── Build activity list for prompt ────────────────────────────────────
        $activityLines = [];
        foreach ($processed as $a) {
            $hrStr = $a['avg_hr']
                ? "HF: {$a['avg_hr']} bpm" . ($a['hr_diff_to_lthr'] !== null ? ' (' . sprintf('%+d', $a['hr_diff_to_lthr']) . ' bpm zur LTHR)' : '')
                : 'HF: keine Daten';
            $activityLines[] = sprintf(
                '- [%s] %s: %.2f km, %.0f min, Pace: %s min/km, %s',
                $a['date'], $a['name'], $a['distance_km'], $a['duration_min'], $a['pace'], $hrStr
            );
        }
        $activitiesText = implode("\n", $activityLines);
        $lthrText       = $lthr ? "{$lthr}" : 'nicht hinterlegt';

        $prompt = <<<PROMPT
Du bist ein Sportwissenschaftler und Lauf-Coach spezialisiert auf Laktatschwellen-Diagnostik (LT2 / Lactate Threshold).

Ziel:
Bestimme die physiologisch plausibelste Schwellenpace (Threshold Pace / LT2 Pace) des Athleten anhand der Trainings- und Wettkampfdaten.

Definition Schwellenpace:
Die Schwellenpace ist die maximale Pace, die typischerweise etwa 45–70 Minuten haltbar ist. Sie entspricht ungefähr der Intensität an der Laktatschwelle (LT2).

Wichtige physiologische Regeln:

* Aktivitäten mit Herzfrequenz nahe der LTHR sind relevant, dürfen aber NICHT automatisch direkt als Schwellenpace interpretiert werden.
* Lange Wettkämpfe (>75 Minuten) liegen häufig leicht unterhalb der tatsächlichen Schwellenpace.
* Halbmarathon-Pace ist typischerweise ca. 3–6 % langsamer als die tatsächliche Schwellenpace.
* Wenn eine Pace länger als 75 Minuten gehalten wurde, muss die Schwellenpace entsprechend etwas schneller geschätzt werden.
* Durchschnitts-Herzfrequenz allein reicht NICHT zur Schwellenbestimmung aus:

  * Cardiac Drift
  * Wettkampfadrenalin
  * Temperatur
  * Ermüdung
  * Koffein
    können die HF verfälschen.
* Neuere Aktivitäten sind wichtiger als ältere.
* Intervalle, Tempodauerläufe und Wettkämpfe sind relevanter als lockere Dauerläufe.

Analyse-Logik:

1. Aktivitäten mit HF innerhalb ±5 bpm zur LTHR:

   * sehr relevant
   * aber Dauer berücksichtigen
2. Aktivitäten mit HF innerhalb ±10 bpm:

   * unterstützende Datenpunkte
3. Aktivitäten mehr als 10 bpm unter LTHR:

   * meist Easy/Recovery
   * nur gering gewichten
4. Läufe >75 Minuten:

   * Pace typischerweise 3–8 % schneller auf Schwelle hochrechnen
5. Läufe zwischen 35–70 Minuten:

   * höchste physiologische Relevanz
6. Intervall- und Tempoeinheiten:

   * stärker gewichten als lockere Läufe
7. Ziel:

   * physiologisch plausible LT2-Pace
   * keine reine HF-Gleichsetzung

Wichtige Regeln:

* Nutze keine einfache Durchschnittsbildung.
* Nutze keine lineare HF-zu-Pace-Umrechnung.
* Berücksichtige Dauer, Belastungsart und physiologische Plausibilität.
* Ignoriere offensichtlich lockere Läufe weit unterhalb der Schwelle weitgehend.
* Wenn Wettkampfdaten vorhanden sind, nutze sie intelligent zur Hochrechnung der Schwellenpace.

Athletendaten:
Schwellen-Herzfrequenz (LTHR): {$lthrText} bpm

Aktivitäten:
{$activitiesText}

Gib ausschließlich dieses JSON zurück:
{"threshold_pace":"M:SS"}
PROMPT;

        // gpt-5.5 is a reasoning model: the JSON output is tiny but the internal
        // reasoning over ~20 activities needs plenty of headroom, otherwise the
        // whole budget is spent on reasoning and the content comes back empty.
        $text = $this->ai->chat('threshold_pace', [
            ['role' => 'system', 'content' => 'Du bist ein präziser Sportwissenschaftler. Antworte ausschließlich mit JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.1, 3000);

        $json = $this->ai->jsonObject($text);
        if ($json !== null) {
            if (isset($json['threshold_pace'])) {
                $result = $this->paceStringToFloat($json['threshold_pace']);
                if ($result !== null) {
                    Log::info('Threshold pace calculated', [
                        'lthr'        => $lthr,
                        'has_hr_data' => $hasAnyHR,
                        'ai_result'   => $json['threshold_pace'],
                        'activities'  => count($processed),
                    ]);
                    return $result;
                }
            }
        }

        Log::warning('Threshold pace AI parse failed', ['text' => $text]);
        return null;
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
