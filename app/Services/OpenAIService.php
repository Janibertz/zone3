<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->model = config('services.openai.model', 'gpt-4o');
    }

    /**
     * Generate AI-powered training analysis for a goal
     *
     * @param array $goalData
     * @param array $progressData
     * @param array $recentActivities
     * @param mixed $wellbeingData
     * @return string
     */
    public function analyzeTraining(array $goalData, array $progressData, array $recentActivities, $wellbeingData = null): string
    {
        $prompt = $this->buildAnalysisPrompt($goalData, $progressData, $recentActivities, $wellbeingData);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Du bist ein erfahrener Lauf-Coach. Gib kurze, ermutigende und actionable Trainingsanalysen auf Deutsch. Sei präzise und praktisch. Verwende Emojis für bessere Readability. Beachte die Wellbeing-Daten des Athleten und passe deine Empfehlungen entsprechend an.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 300,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI API Error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return 'KI-Analyse konnte nicht geladen werden.';
            }

            $data = $response->json();
            return $data['choices'][0]['message']['content'] ?? 'Keine Analyse verfügbar.';

        } catch (\Exception $e) {
            Log::error('OpenAI Service Error', ['error' => $e->getMessage()]);
            return 'KI-Service Fehler: ' . $e->getMessage();
        }
    }

    /**
     * Build the prompt for training analysis
     */
    protected function buildAnalysisPrompt(array $goalData, array $progressData, array $recentActivities, $wellbeingData = null): string
    {
        $activitiesSummary = $this->summarizeActivities($recentActivities);
        $wellbeing = $this->formatWellbeingData($wellbeingData);

        return <<<PROMPT
Analysiere bitte das folgende Trainingsfortschritt und gib eine ermutigende, actionable Analyse:

**Ziel:** {$goalData['name']}
- Zielstrecke: {$progressData['target_distance_km']} km
- Aktueller Fortschritt: {$progressData['completed_distance_km']} km ({$progressData['progress_percentage']}%)
- Status: {$this->formatStatus($progressData['status'])}
- Restzeit: {$progressData['days_remaining']} Tage
- Trainingseinheiten: {$progressData['activities_count']}

**Letzte Aktivitäten:**
{$activitiesSummary}

**Wellbeing Status:**
{$wellbeing}

Bitte gib eine kurze (max 3-4 Sätze), ermutigende Analyse mit:
1. Bewertung des aktuellen Fortschritts (beachte Wellbeing-Status)
2. Einen praktischen Tipp für die nächsten Tage (angepasst an Energie/Verletzung)
3. Motivierende Worte

Benutze Emojis und halte es prägnant!
PROMPT;
    }

    /**
     * Format wellbeing data for prompt
     */
    protected function formatWellbeingData($wellbeingData): string
    {
        if (!$wellbeingData) {
            return 'Keine Wellbeing-Daten erfasst.';
        }

        $status = $wellbeingData->getStatus();
        $score = $wellbeingData->getWellbeingScore();
        
        $data = "- Status: $status (Score: $score/10)\n";
        $data .= "- Energielevel: {$wellbeingData->energy_level}/10\n";
        $data .= "- Stimmung: {$wellbeingData->mood}/10\n";
        $data .= "- Schlafqualität: {$wellbeingData->sleep_quality}/10\n";
        $data .= "- Muskelkater: {$wellbeingData->muscle_soreness}/10\n";
        $data .= "- Stress-Level: {$wellbeingData->stress_level}/10\n";
        
        if ($wellbeingData->is_sick) {
            $data .= "- ⚠️ Athlet ist krank\n";
        }
        if ($wellbeingData->is_injured) {
            $data .= "- ⚠️ Athlet ist verletzt\n";
        }
        if ($wellbeingData->notes) {
            $data .= "- Notizen: {$wellbeingData->notes}\n";
        }

        return $data;
    }

    /**
     * Summarize recent activities
     */
    protected function summarizeActivities(array $activities): string
    {
        if (empty($activities)) {
            return 'Keine Aktivitäten vorhanden.';
        }

        $summary = [];
        foreach (array_slice($activities, 0, 3) as $activity) {
            $distance = $activity['distance'] / 1000; // meters to km
            $pace = $this->calculatePace($activity['average_speed']);
            $summary[] = sprintf("- %s: %.2f km, Pace: %s", $activity['name'], $distance, $pace);
        }

        return implode("\n", $summary);
    }

    /**
     * Calculate pace from m/s
     */
    protected function calculatePace(float $metersPerSecond): string
    {
        if ($metersPerSecond <= 0) return '—';
        $secondsPerKm = 1000 / $metersPerSecond;
        $minutes = (int)($secondsPerKm / 60);
        $seconds = (int)($secondsPerKm % 60);
        return "{$minutes}:{$seconds}";
    }

    /**
     * Format status for display
     */
    protected function formatStatus(string $status): string
    {
        $statusMap = [
            'completed' => '✅ Abgeschlossen',
            'on_track_ahead' => '🚀 Voraus im Plan',
            'on_track' => '🎯 Im Plan',
            'behind' => '⚠️ Hinter dem Plan',
            'missed' => '❌ Verpasst',
        ];

        return $statusMap[$status] ?? $status;
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

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Du bist ein erfahrener Lauf-Coach. Erstelle praktische, machbare Trainingspläne auf Deutsch.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 400,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI Plan Generation Error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return 'Trainingsplan konnte nicht erstellt werden.';
            }

            $data = $response->json();
            return $data['choices'][0]['message']['content'] ?? 'Kein Plan verfügbar.';

        } catch (\Exception $e) {
            Log::error('OpenAI Training Plan Error', ['error' => $e->getMessage()]);
            return 'Fehler beim Plan erstellen: ' . $e->getMessage();
        }
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

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Du bist ein präziser Lauf-Coach. Antworte nur mit JSON.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.2,
                'max_tokens' => 260,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI Zone Calculation Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $data = $response->json();
            $text = data_get($data, 'choices.0.message.content', '');

            // Extract first JSON object from response
            if (preg_match('/\{.*\}/s', $text, $matches)) {
                $json = json_decode($matches[0], true);
                if (json_last_error() === JSON_ERROR_NONE && isset($json['pace_zones'])) {
                    return $json['pace_zones'];
                }
            }

            Log::warning('OpenAI Zone Calculation parse failed', ['text' => $text]);
            return [];

        } catch (\Exception $e) {
            Log::error('OpenAI Zone Calculation Exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function generateTodayRecommendation(array $runnerProfile, ?array $yesterdayActivity, ?array $wellbeingEntry, ?array $goal, array $progress): string
    {
        $profileText = $runnerProfile ? "Runner Profile:\n- LUTHR: {$runnerProfile['threshold_heart_rate']} bpm\n- Max HR: {$runnerProfile['max_heart_rate']} bpm\n- Schwellenpace: {$runnerProfile['threshold_speed']} (min/km)\n" : "Kein Runner Profile vorhanden.\n";
        $activityText = $yesterdayActivity ? "Letzte Aktivität (gestern):\n- Name: {$yesterdayActivity['name']}\n- Distanz: " . round($yesterdayActivity['distance']/1000,2) . " km\n- Dauer: " . $this->formatSeconds($yesterdayActivity['moving_time']) . "\n-Durchschnitts-Pace: " . ($yesterdayActivity['average_speed'] ? $this->calculatePace($yesterdayActivity['average_speed']) : '—') . "\n" : "Keine Aktivität von gestern.\n";
        $wellbeingText = $wellbeingEntry ? "Wellbeing heute:\n- Energie: {$wellbeingEntry['energy_level']}/10\n- Stimmung: {$wellbeingEntry['mood']}/10\n- Schlaf: {$wellbeingEntry['sleep_quality']}/10\n- Muskelkater: {$wellbeingEntry['muscle_soreness']}/10\n- Stress: {$wellbeingEntry['stress_level']}/10\n" : "Kein Wellbeing-Eintrag heute.\n";
        $goalText = $goal ? "Aktives Ziel:\n- {$goal['name']} ({$goal['target_value']} {$goal['unit']})\n- Zeitraum: {$goal['start_date']} bis {$goal['end_date']}\n" : "Kein aktives Ziel.\n";
        $progressText = "Progress:\n- Fertig: " . ($progress['completed_distance_km'] ?? 0) . " / " . ($progress['target_distance_km'] ?? 0) . " km ({$progress['progress_percentage']}%)\n- Status: {$progress['status']}\n- Tage rest: {$progress['days_remaining']}\n";

        $prompt = <<<PROMPT
Du bist ein sehr präziser Lauf-Coach. Erstelle eine konkrete Trainingsempfehlung für heute basierend auf folgenden Daten:

{$profileText}
{$activityText}
{$wellbeingText}
{$goalText}
{$progressText}

1) Empfohlenes Training für heute (Distanz, pace, Intensität, Dauer)
2) Grund (anhand Training + Wellbeing + Ziel)
3) Ausfall- / Regenerationsempfehlung falls erholt / müde

Antworte auf Deutsch, mit maximal 6 Sätzen, gut strukturiert.
PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Du bist ein erfahrener Lauf-Coach. Kurze, präzise Empfehlungen.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.5,
                'max_tokens' => 250,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI Today Recommendation Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return 'Keine Empfehlung verfügbar (Fehler bei KI).';
            }

            $data = $response->json();
            return $data['choices'][0]['message']['content'] ?? 'Keine Empfehlung verfügbar.';

        } catch (\Exception $e) {
            Log::error('OpenAI Today Recommendation Exception', ['error' => $e->getMessage()]);
            return 'Fehler bei KI-Empfehlung: ' . $e->getMessage();
        }
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
                'pace'              => $this->calculatePace($activity['average_speed']),
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

        // ── Mathematical pre-estimate ──────────────────────────────────────────
        // Sort by total weight descending — highest-confidence activities first
        $sorted = $processed;
        usort($sorted, fn($a, $b) => $b['total_weight'] <=> $a['total_weight']);

        // Take top 50% by weight for the math estimate
        $topHalf      = array_slice($sorted, 0, max(1, (int)(count($sorted) * 0.5)));
        $weightSum    = array_sum(array_column($topHalf, 'total_weight'));
        $weightedPace = array_sum(array_map(fn($a) => $a['pace_sec'] * $a['total_weight'], $topHalf));
        $avgPaceSec   = $weightSum > 0 ? $weightedPace / $weightSum : 0;

        // If top activities are mostly above LTHR (too fast), nudge pace down slightly
        $aboveLTHR = array_filter($topHalf, fn($a) => ($a['hr_diff_to_lthr'] ?? 0) > 5);
        if (count($aboveLTHR) / count($topHalf) > 0.5) {
            $avgPaceSec *= 1.04; // race pace → slightly slower to threshold pace
        }

        // If top activities are tempo (20-35 min) without HR, adjust to threshold
        $tempoOnly = !$lthr || !$hasAnyHR;
        $avgDuration = array_sum(array_column($topHalf, 'duration_min')) / count($topHalf);
        if ($tempoOnly && $avgDuration < 35) {
            $avgPaceSec *= 1.06;
        }

        $mathMins     = (int)($avgPaceSec / 60);
        $mathSecs     = (int)($avgPaceSec % 60);
        $mathEstimate = sprintf('%d:%02d', $mathMins, $mathSecs);

        // ── Build AI prompt ────────────────────────────────────────────────────
        $lthrContext = $lthr
            ? "Schwellen-Herzfrequenz (LTHR) des Athleten: **{$lthr} bpm**"
            : 'Keine LTHR hinterlegt — nur Dauer-basierte Analyse.';

        $hrContext = $hasAnyHR
            ? 'Herzfrequenzdaten vorhanden — Pace/HF-Analyse möglich.'
            : 'Keine HF-Daten in diesen Aktivitäten.';

        $activityLines = [];
        foreach ($processed as $a) {
            $hrStr = $a['avg_hr']
                ? "HF: {$a['avg_hr']} bpm" . ($a['hr_diff_to_lthr'] !== null ? ' (' . sprintf('%+d', $a['hr_diff_to_lthr']) . ' bpm zu LTHR)' : '')
                : 'HF: keine Daten';
            $activityLines[] = sprintf(
                '- [%s] %s: %.2f km, %.0f min, Pace: %s min/km, %s | %s | Gewicht: %.2f | %s',
                $a['date'], $a['name'], $a['distance_km'], $a['duration_min'],
                $a['pace'], $hrStr, $a['hr_category'], $a['total_weight'], $a['hr_note']
            );
        }
        $activitiesText = implode("\n", $activityLines);

        $prompt = <<<PROMPT
Du bist ein Sportwissenschaftler und Lauf-Coach spezialisiert auf Laktatschwellen-Diagnostik.

**{$lthrContext}**
{$hrContext}

**Definition Schwellenpace (LTHR-Pace):** Die Pace, bei der der Athlet exakt an seiner Laktatschwelle läuft — die Herzfrequenz entspricht dann der LTHR. Maximal 45-60 Minuten haltbar.

**Aktivitäten (absteigend nach Relevanz gewichtet):**
{$activitiesText}

**Analyse-Logik:**
1. Aktivitäten mit HF direkt an LTHR (±5 bpm) → ihre Pace IST die Schwellenpace (höchste Priorität)
2. Aktivitäten mit HF ±10 bpm um LTHR → nahe der Schwellenpace, leicht korrigieren
3. Aktivitäten mit HF >10 bpm unter LTHR → Easy-Lauf, ignorieren
4. Aktivitäten ohne HF → nach Dauer beurteilen (35-75 min = Schwellenbereich)
5. Neuere Aktivitäten (höheres Gewicht) stärker berücksichtigen
6. Mathematische Vorberechnung: **{$mathEstimate} min/km** — als Anker verwenden

**Gib ausschließlich dieses JSON zurück:**
{"threshold_pace": "M:SS"}
PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model'    => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Du bist ein präziser Sportwissenschaftler. Antworte ausschließlich mit JSON.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'temperature' => 0.1,
                'max_tokens'  => 50,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI Threshold Pace Error', ['status' => $response->status(), 'body' => $response->body()]);
                return $this->paceStringToFloat($mathEstimate);
            }

            $text = data_get($response->json(), 'choices.0.message.content', '');

            if (preg_match('/\{.*?\}/s', $text, $matches)) {
                $json = json_decode($matches[0], true);
                if (json_last_error() === JSON_ERROR_NONE && isset($json['threshold_pace'])) {
                    $result = $this->paceStringToFloat($json['threshold_pace']);
                    if ($result !== null) {
                        Log::info('Threshold pace calculated', [
                            'lthr'          => $lthr,
                            'has_hr_data'   => $hasAnyHR,
                            'math_estimate' => $mathEstimate,
                            'ai_result'     => $json['threshold_pace'],
                            'activities'    => count($processed),
                        ]);
                        return $result;
                    }
                }
            }

            Log::warning('Threshold pace AI parse failed, using math estimate', ['text' => $text]);
            return $this->paceStringToFloat($mathEstimate);

        } catch (\Exception $e) {
            Log::error('Threshold Pace Exception', ['error' => $e->getMessage()]);
            return $this->paceStringToFloat($mathEstimate);
        }
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

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model'    => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Du bist ein präziser Sportwissenschaftler. Antworte ausschließlich mit validem JSON.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'temperature' => 0.2,
                'max_tokens'  => 80,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI Profile Estimation Error', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $text = data_get($response->json(), 'choices.0.message.content', '');

            if (preg_match('/\{.*?\}/s', $text, $matches)) {
                $json = json_decode($matches[0], true);
                if (
                    json_last_error() === JSON_ERROR_NONE &&
                    isset($json['threshold_heart_rate'], $json['max_heart_rate'], $json['threshold_speed'])
                ) {
                    return [
                        'threshold_heart_rate' => (int) $json['threshold_heart_rate'],
                        'max_heart_rate'        => (int) $json['max_heart_rate'],
                        'threshold_speed'       => (string) $json['threshold_speed'],
                    ];
                }
            }

            Log::warning('OpenAI Profile Estimation parse failed', ['text' => $text]);
            return null;

        } catch (\Exception $e) {
            Log::error('OpenAI Profile Estimation Exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate a structured 10-day training plan for a specific race event.
     *
     * Returns an array of exactly 10 session objects, or null on failure.
     * Each session: date, type, title, description, distance_km, duration_min,
     *               pace_target, zone, intensity.
     */
    public function generateEventTrainingPlan(
        \App\Models\Event $event,
        ?array $profile,
        array $recentActivities,
        array $wellbeingData,
        array $sessionRatings = [],
        ?array $weeklyAvailability = null,
        array $availabilityOverrides = [],
        ?array $trainingLoad = null,
    ): ?array {
        $today        = now()->format('Y-m-d');
        $eventDate    = $event->event_date->format('Y-m-d');
        $daysUntil    = $event->days_until;
        $targetH      = $event->target_time_hours;
        $targetM      = $event->target_time_minutes;
        $targetTime   = $targetH > 0 ? sprintf('%d:%02d Std', $targetH, $targetM) : "{$targetM} Min";
        $distLabel    = $event->distance_label;
        $priority     = $event->priority;
        $priorityText = match ($priority) {
            'A' => 'A-Event (Hauptrennen — höchste Priorität)',
            'B' => 'B-Event (wichtiges Rennen)',
            'C' => 'C-Event (Trainingsrennen)',
        };

        // Profile section
        $profileText = $profile
            ? "Athletenprofil:\n- Schwellenpace: {$profile['threshold_pace']} min/km\n- LTHR: {$profile['threshold_hr']} bpm\n- Max HR: {$profile['max_hr']} bpm"
            : "Athletenprofil: nicht hinterlegt — plane konservativ.";

        // Activities summary (last 4 weeks, max 10)
        $actLines = [];
        foreach (array_slice($recentActivities, 0, 10) as $a) {
            $hr      = $a['avg_hr'] ? " | HF: {$a['avg_hr']} bpm" : '';
            $pace    = $a['pace'] ? " | Pace: {$a['pace']} min/km" : '';
            $actLines[] = "- [{$a['date']}] {$a['name']}: {$a['distance_km']} km, {$a['duration_min']} min{$pace}{$hr}";
        }
        $activitiesText = empty($actLines)
            ? 'Keine Aktivitäten in den letzten 4 Wochen.'
            : implode("\n", $actLines);

        // Wellbeing summary
        if (! empty($wellbeingData)) {
            $avgEnergy   = round(array_sum(array_column($wellbeingData, 'energy')) / count($wellbeingData), 1);
            $avgSleep    = round(array_sum(array_column($wellbeingData, 'sleep')) / count($wellbeingData), 1);
            $avgSoreness = round(array_sum(array_column($wellbeingData, 'soreness')) / count($wellbeingData), 1);
            $avgStress   = round(array_sum(array_column($wellbeingData, 'stress')) / count($wellbeingData), 1);
            $sickCount   = count(array_filter($wellbeingData, fn ($w) => $w['is_sick']));
            $injuredCount= count(array_filter($wellbeingData, fn ($w) => $w['is_injured']));
            $wellbeingText = "Wellbeing (Ø letzte 14 Tage):\n- Energie: {$avgEnergy}/10 | Schlaf: {$avgSleep}/10 | Muskelkater: {$avgSoreness}/10 | Stress: {$avgStress}/10";
            if ($sickCount > 0) $wellbeingText .= "\n- ⚠️ Krank an {$sickCount} Tagen";
            if ($injuredCount > 0) $wellbeingText .= "\n- ⚠️ Verletzt an {$injuredCount} Tagen";
        } else {
            $wellbeingText = 'Wellbeing: keine Daten vorhanden.';
        }

        // Session ratings summary for AI learning
        $ratingsText = 'Keine Bewertungsdaten vorhanden.';
        if (! empty($sessionRatings)) {
            $ratingLines = [];
            foreach ($sessionRatings as $r) {
                $stars  = $r['rating'] ? str_repeat('⭐', $r['rating']) : '–';
                $rpe    = $r['effort_perceived'] ? "RPE {$r['effort_perceived']}/10" : '';
                $note   = $r['feeling_notes'] ? " | \"{$r['feeling_notes']}\"" : '';
                $ratingLines[] = "- [{$r['date']}] {$r['type']} {$r['distance_km']}km — {$stars} {$rpe}{$note}";
            }
            // Compute avg rating and RPE for coaching context
            $avgRating = round(array_sum(array_column($sessionRatings, 'rating')) / count($sessionRatings), 1);
            $rpeValues = array_filter(array_column($sessionRatings, 'effort_perceived'));
            $avgRpe    = $rpeValues ? round(array_sum($rpeValues) / count($rpeValues), 1) : null;
            $ratingsText = "Ø Bewertung: {$avgRating}/5" . ($avgRpe ? " | Ø RPE: {$avgRpe}/10" : '') . "\n" . implode("\n", array_slice($ratingLines, 0, 10));
        }

        // Availability text
        $dayNames = [
            'monday' => 'Montag', 'tuesday' => 'Dienstag', 'wednesday' => 'Mittwoch',
            'thursday' => 'Donnerstag', 'friday' => 'Freitag', 'saturday' => 'Samstag', 'sunday' => 'Sonntag',
        ];
        if ($weeklyAvailability) {
            $avLines = [];
            foreach ($dayNames as $key => $label) {
                $day = $weeklyAvailability[$key] ?? null;
                if (!$day) { $avLines[] = "- {$label}: nicht verfügbar"; continue; }
                if (! ($day['available'] ?? false)) { $avLines[] = "- {$label}: nicht verfügbar"; }
                else { $avLines[] = "- {$label}: verfügbar, max. {$day['duration_min']} Minuten"; }
            }
            $availabilityText = "Wöchentliche Verfügbarkeit des Athleten:\n" . implode("\n", $avLines);
        } else {
            $availabilityText = 'Wöchentliche Verfügbarkeit: keine Angabe — verteile Training gleichmäßig.';
        }

        // Training load context (CTL / ATL / TSB)
        $loadText = 'Trainingsbelastung: keine Daten vorhanden.';
        if ($trainingLoad && ($trainingLoad['ctl'] > 0 || $trainingLoad['atl'] > 0)) {
            $tsb       = $trainingLoad['tsb'];
            $tsbSign   = $tsb >= 0 ? "+{$tsb}" : "{$tsb}";
            $formLabel = $trainingLoad['form_label'];
            $loadText  = "Aktuelle Trainingsbelastung:\n"
                . "- CTL (Fitness, 42-Tage-EMA): {$trainingLoad['ctl']}\n"
                . "- ATL (Ermüdung, 7-Tage-EMA): {$trainingLoad['atl']}\n"
                . "- TSB (Form = CTL−ATL): {$tsbSign} → Status: {$formLabel}\n"
                . "Übermüdet (<−30): Nur leichte Einheiten / Ruhe. Belastet (−30 bis −10): Normaler Trainingsblock. Optimal (−10 bis +5): Wettkampfbereit. Frisch (+5 bis +25): Tapering aktiv. Ausgeruht (>+25): Volumen erhöhen.";
        }

        // Per-date overrides
        $overrideText = '';
        if (! empty($availabilityOverrides)) {
            $ovLines = [];
            foreach ($availabilityOverrides as $date => $ov) {
                if (! ($ov['available'] ?? true)) { $ovLines[] = "- {$date}: NICHT verfügbar (override)"; }
                else { $ovLines[] = "- {$date}: verfügbar, max. {$ov['duration_min']} Minuten (override)"; }
            }
            $overrideText = "\n\n**Einzelne Tages-Ausnahmen (haben Vorrang vor der Wochenverfügbarkeit):**\n" . implode("\n", $ovLines);
        }

        $prompt = <<<PROMPT
Du bist ein professioneller Lauf-Coach. Erstelle einen 10-Tages-Trainingsplan für folgendes Rennevent.

**Event:**
- Name: {$event->name}
- Datum: {$eventDate} (in {$daysUntil} Tagen)
- Distanz: {$distLabel}
- Priorität: {$priorityText}
- Zielzeit: {$targetTime}

**{$profileText}**

**Letzte Aktivitäten (4 Wochen):**
{$activitiesText}

**{$wellbeingText}**

**{$loadText}**

**Bisherige Einheitsbewertungen (Athleten-Feedback):**
{$ratingsText}

**{$availabilityText}**{$overrideText}

**Planungsregeln:**
- Starte den Plan ab heute ({$today})
- Der letzte Tag im Plan ist IMMER der Renntag ({$eventDate}) — niemals danach
- Plane maximal bis {$eventDate}, auch wenn das weniger als 10 Tage sind
- Am Renntag ({$eventDate}): type="race_prep", title="{$event->name}", beschreibe das Rennen selbst
- Passe die Intensität an den Zeitraum bis zum Rennen an: {$daysUntil} Tage
- Bei >30 Tagen: normaler Aufbau (Volumen + Tempo)
- Bei 10-30 Tagen: Tapering einleiten (Volumen reduzieren, Qualität halten)
- Bei <10 Tagen: starkes Tapering, nur leichte Läufe und Ruhetage
- Berücksichtige Wellbeing-Daten: schlechter Schlaf/hoher Stress → leichtere Einheiten
- Berücksichtige die Trainingsbelastung: TSB < −30 (Übermüdet) → Volumen stark reduzieren, mehr Ruhetage; TSB > +15 (zu frisch) → Volumen erhöhen
- Mindestens ein Ruhetag pro Woche
- A-Events: max. Leistungsoptimierung; C-Events: Trainingsrennen, moderate Belastung
- WICHTIG: Plane nur Tage von heute ({$today}) bis zum Renntag ({$eventDate}). Kein Tag nach {$eventDate}.
- Lerne aus den Athleten-Bewertungen: niedrige Bewertungen (1-2⭐) oder hohe RPE (≥8) bei bestimmten Typen → weniger davon oder leichter planen; hohe Bewertungen (4-5⭐) → mehr davon
- VERFÜGBARKEIT: Plane Training AUSSCHLIESSLICH an verfügbaren Tagen. An nicht verfügbaren Tagen IMMER type="rest". Die Trainingsdauer darf die angegebene Maximalzeit NIEMALS überschreiten. Tages-Ausnahmen haben Vorrang.

**Antworte ausschließlich mit einem JSON-Array (zwischen 1 und 10 Objekte, je nach verfügbaren Tagen bis zum Rennen):**
[
  {
    "date": "YYYY-MM-DD",
    "type": "rest|easy_run|tempo_run|interval|long_run|race_prep",
    "title": "Kurzer Titel (max 40 Zeichen)",
    "description": "Beschreibung der Einheit (2-3 Sätze, konkrete Anweisungen)",
    "distance_km": 0,
    "duration_min": 0,
    "pace_target": "5:30-6:00 oder null bei Ruhetag",
    "zone": 2,
    "intensity": "rest|low|medium|high"
  }
]
Für Ruhetage: distance_km=0, duration_min=0, pace_target=null, zone=null.
PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(60)->post($this->baseUrl . '/chat/completions', [
                'model'    => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Du bist ein präziser Lauf-Coach. Antworte ausschließlich mit einem validen JSON-Array ohne zusätzlichen Text.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'temperature' => 0.6,
                'max_tokens'  => 2500,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI Event Training Plan Error', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $text = data_get($response->json(), 'choices.0.message.content', '');

            // Extract JSON array from response
            if (preg_match('/\[.*\]/s', $text, $matches)) {
                $sessions = json_decode($matches[0], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($sessions) && count($sessions) > 0) {
                    Log::info('Event training plan generated', [
                        'event_id' => $event->id,
                        'sessions' => count($sessions),
                    ]);
                    return $sessions;
                }
            }

            Log::warning('Event training plan parse failed', ['text' => $text]);
            return null;

        } catch (\Exception $e) {
            Log::error('Event Training Plan Exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Adjust a single training session based on today's wellbeing data.
     * Returns updated session fields or null on failure.
     */
    public function adjustSessionForWellbeing(array $session, \App\Models\WellbeingEntry $wellbeing): ?array
    {
        $sick    = $wellbeing->is_sick    ? 'Ja' : 'Nein';
        $injured = $wellbeing->is_injured ? 'Ja' : 'Nein';

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
- Verletzt: {$injured}

**Anpassungsregeln:**
- Krank oder verletzt → Typ "rest", Distanz 0, Dauer 0, Intensität "rest"
- Energie ≤ 3 oder Schlaf ≤ 3 → Intensität auf "low" reduzieren, Distanz um 30-40% kürzen
- Muskelkater ≥ 7 → Typ zu "easy_run", Intensität "low", Pace 30-45 Sek langsamer
- Stress ≥ 8 → Dauer kürzen um 20%, Intensität reduzieren
- Sonst → leichte Anpassung der Beschreibung mit Hinweis auf Wellbeing

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

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model'    => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Du bist ein präziser Lauf-Coach. Antworte ausschließlich mit validem JSON.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'temperature' => 0.4,
                'max_tokens'  => 300,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI Session Adjust Error', ['status' => $response->status()]);
                return null;
            }

            $text = data_get($response->json(), 'choices.0.message.content', '');
            if (preg_match('/\{.*\}/s', $text, $matches)) {
                $json = json_decode($matches[0], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $json;
                }
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Session Adjust Exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

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
Max. 3 Punkte pro Abschnitt. Konkrete Mengen, Produkte, Zeitangaben. Kein Allgemeinwissen.
PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model'    => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Du bist ein Ernährungs- und Laufexperte. Antworte ausschließlich mit validem JSON.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'temperature' => 0.5,
                'max_tokens'  => 600,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI Nutrition Tips Error', ['status' => $response->status()]);
                return null;
            }

            $text = data_get($response->json(), 'choices.0.message.content', '');
            if (preg_match('/\{.*\}/s', $text, $matches)) {
                $json = json_decode($matches[0], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $json;
                }
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Nutrition Tips Exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate a weekly review for the athlete (runs every Monday, cached in DB).
     */
    public function generateWeeklyReview(\App\Models\User $user, string $weekStart, string $weekEnd): ?string
    {
        // Completed sessions this week
        $sessions = \App\Models\TrainingSession::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereBetween('planned_date', [$weekStart, $weekEnd])
            ->orderBy('planned_date')
            ->get();

        // Skipped sessions
        $skipped = \App\Models\TrainingSession::where('user_id', $user->id)
            ->where('status', 'skipped')
            ->whereBetween('planned_date', [$weekStart, $weekEnd])
            ->count();

        // Wellbeing this week
        $wellbeing = $user->wellbeingEntries()
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->get();

        if ($sessions->isEmpty() && $wellbeing->isEmpty()) {
            return null; // Nothing to review
        }

        // Build session summary
        $totalKm    = $sessions->sum(fn ($s) => $s->distance_km ?? 0);
        $totalMin   = $sessions->sum(fn ($s) => $s->duration_min ?? 0);
        $sessionLines = $sessions->map(fn ($s) => sprintf(
            '- [%s] %s: %s km, %s min%s%s',
            $s->planned_date->format('D'),
            $s->title,
            number_format($s->distance_km ?? 0, 1),
            $s->duration_min ?? 0,
            $s->rating  ? ", Bewertung: {$s->rating}/5⭐" : '',
            $s->effort_perceived ? ", RPE {$s->effort_perceived}/10" : ''
        ))->implode("\n");

        $wellbeingLines = '';
        if ($wellbeing->isNotEmpty()) {
            $avgE = round($wellbeing->avg('energy_level'), 1);
            $avgS = round($wellbeing->avg('sleep_quality'), 1);
            $avgM = round($wellbeing->avg('muscle_soreness'), 1);
            $sick = $wellbeing->where('is_sick', true)->count();
            $wellbeingLines = "Wellbeing Ø: Energie {$avgE}/10 | Schlaf {$avgS}/10 | Muskelkater {$avgM}/10" . ($sick > 0 ? " | {$sick} Krankheitstage" : '');
        }

        $prompt = <<<PROMPT
Du bist Lauf-Coach. Schreibe einen kurzen, motivierenden Wochenrückblick für deinen Athleten. Sei direkt, konkret und ehrlich — weder überschwänglich noch demotivierend.

**Trainingswoche {$weekStart} – {$weekEnd}:**
{$sessionLines}
Übersprungene Einheiten: {$skipped}
Gesamt: {$totalKm} km / {$totalMin} min

{$wellbeingLines}

**Dein Review (max. 150 Wörter):**
- Was lief gut diese Woche?
- Was fiel auf (Belastung, Wellbeing, Konstanz)?
- Eine konkrete Empfehlung für die kommende Woche

Schreibe fließend, kein JSON, kein Markdown mit #-Überschriften. Direkte Ansprache (du).
PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(30)->post($this->baseUrl . '/chat/completions', [
                'model'    => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Du bist ein erfahrener Lauf-Coach. Antworte auf Deutsch, kurz und präzise.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'temperature' => 0.7,
                'max_tokens'  => 300,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI Weekly Review Error', ['status' => $response->status()]);
                return null;
            }

            return trim(data_get($response->json(), 'choices.0.message.content', '')) ?: null;
        } catch (\Exception $e) {
            Log::error('Weekly Review Exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function formatSeconds(int $seconds): string
    {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}


