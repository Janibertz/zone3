<?php

namespace App\Services;

use App\Models\AiLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://api.openai.com/v1';
    protected ?string $coachPersonality = null;
    protected ?int $userId = null;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->model = config('services.openai.model', 'gpt-4o');
    }

    public function withCoach(?string $personalityPrompt): static
    {
        $this->coachPersonality = $personalityPrompt;
        return $this;
    }

    public function forUser(?int $userId): static
    {
        $this->userId = $userId;
        return $this;
    }

    protected function buildSystemPrompt(string $base): string
    {
        if ($this->coachPersonality) {
            return $this->coachPersonality . ' ' . $base;
        }
        return $base;
    }

    /**
     * Central OpenAI HTTP call with automatic AiLog entry.
     * Returns content string from choices[0].message.content, or null on failure.
     */
    protected function callOpenAI(
        string $callType,
        array  $messages,
        float  $temperature,
        int    $maxTokens,
        int    $timeout = 30
    ): ?string {
        $startMs = (int) round(microtime(true) * 1000);

        $userContent = '';
        foreach (array_reverse($messages) as $m) {
            if ($m['role'] === 'user') { $userContent = $m['content']; break; }
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->timeout($timeout)->post($this->baseUrl . '/chat/completions', [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => $temperature,
                'max_tokens'  => $maxTokens,
            ]);

            $durationMs = (int) round(microtime(true) * 1000) - $startMs;
            $body       = $response->json() ?? [];
            $content    = data_get($body, 'choices.0.message.content', '');
            $usage      = $body['usage'] ?? [];
            $promptTok  = $usage['prompt_tokens']     ?? 0;
            $compTok    = $usage['completion_tokens'] ?? 0;
            $totalTok   = $usage['total_tokens']      ?? ($promptTok + $compTok);
            $cost       = AiLog::calculateCost($this->model, $promptTok, $compTok);

            AiLog::create([
                'user_id'          => $this->userId,
                'call_type'        => $callType,
                'model'            => $this->model,
                'prompt_tokens'    => $promptTok,
                'completion_tokens'=> $compTok,
                'total_tokens'     => $totalTok,
                'cost_eur'         => $cost,
                'duration_ms'      => $durationMs,
                'prompt_preview'   => mb_substr($userContent, 0, 500),
                'response_preview' => mb_substr($content, 0, 500),
                'full_prompt'      => $userContent,
                'full_response'    => $content,
                'status'           => $response->failed() ? 'error' : 'success',
                'error_message'    => $response->failed()
                    ? ('HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500))
                    : null,
            ]);

            if ($response->failed()) {
                Log::error("OpenAI {$callType} Error", ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return $content;

        } catch (\Exception $e) {
            $durationMs = (int) round(microtime(true) * 1000) - $startMs;

            AiLog::create([
                'user_id'       => $this->userId,
                'call_type'     => $callType,
                'model'         => $this->model,
                'duration_ms'   => $durationMs,
                'status'        => 'error',
                'error_message' => $e->getMessage(),
            ]);

            Log::error("OpenAI {$callType} Exception", ['error' => $e->getMessage()]);
            return null;
        }
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

        $content = $this->callOpenAI('goal_analysis', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Gib kurze, ermutigende und actionable Trainingsanalysen auf Deutsch. Sei präzise und praktisch. Verwende Emojis für bessere Readability. Beachte die Wellbeing-Daten des Athleten und passe deine Empfehlungen entsprechend an.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.7, 300);

        return $content ?? 'KI-Analyse konnte nicht geladen werden.';
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

        $content = $this->callOpenAI('plan', [
            ['role' => 'system', 'content' => 'Du bist ein erfahrener Lauf-Coach. Erstelle praktische, machbare Trainingspläne auf Deutsch.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.7, 400);

        return $content ?? 'Trainingsplan konnte nicht erstellt werden.';
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

            $text = $this->callOpenAI('pace_zones', [
                ['role' => 'system', 'content' => 'Du bist ein präziser Lauf-Coach. Antworte nur mit JSON.'],
                ['role' => 'user',   'content' => $prompt],
            ], 0.2, 260);

            if ($text && preg_match('/\{.*\}/s', $text, $matches)) {
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

    /**
     * Generate a structured training recommendation for today.
     * Returns an associative array with keys: type, title, description,
     * distance_km, duration_min, pace_target, zone, intensity.
     * Returns null on failure.
     */
    public function generateTodayRecommendation(?array $runnerProfile, ?array $yesterdayActivity, ?array $wellbeingEntry, ?array $goal, array $progress, array $upcomingEvents = [], ?array $todayAvailability = null): ?array
    {
        $profileText = $runnerProfile ? "Runner Profile:\n- LTHR: {$runnerProfile['threshold_heart_rate']} bpm\n- Max HR: {$runnerProfile['max_heart_rate']} bpm\n- Schwellenpace: {$runnerProfile['threshold_speed']} min/km\n" : "Kein Runner Profile vorhanden.\n";
        $activityText = $yesterdayActivity ? "Letzte Aktivität (gestern):\n- " . round($yesterdayActivity['distance']/1000,2) . " km in " . $this->formatSeconds($yesterdayActivity['moving_time']) . " · Pace: " . ($yesterdayActivity['average_speed'] ? $this->calculatePace($yesterdayActivity['average_speed']) : '—') . "\n" : "Keine Aktivität von gestern.\n";
        $wellbeingText = $wellbeingEntry ? "Wellbeing heute: Energie {$wellbeingEntry['energy_level']}/10, Schlaf {$wellbeingEntry['sleep_quality']}/10, Muskelkater {$wellbeingEntry['muscle_soreness']}/10, Stress {$wellbeingEntry['stress_level']}/10\n" : "Kein Wellbeing.\n";
        $goalText = $goal ? "Ziel: {$goal['name']} (bis {$goal['end_date']})\n" : "Kein aktives Ziel.\n";

        // Upcoming events / taper logic
        $eventsText = '';
        $taperWarning = '';
        if (!empty($upcomingEvents)) {
            $lines = array_map(
                fn ($e) => "- {$e['name']} ({$e['distance_label']}) am {$e['event_date']} (in {$e['days_until']} Tagen)",
                $upcomingEvents
            );
            $eventsText = "Kommende Wettkämpfe:\n" . implode("\n", $lines) . "\n";

            $nearest = $upcomingEvents[0];
            $daysUntil = $nearest['days_until'];
            if ($daysUntil <= 1) {
                $taperWarning = "WICHTIG: Wettkampf morgen oder heute! Kein Training — nur lockeres Einlaufen erlaubt. Empfehle rest oder sehr kurzes easy_run.\n";
            } elseif ($daysUntil <= 3) {
                $taperWarning = "WICHTIG: Wettkampf in {$daysUntil} Tagen! Nur leichtes Regenerationstraining, kein intensiver Reiz. Maximal easy_run, Zone 1–2.\n";
            } elseif ($daysUntil <= 7) {
                $taperWarning = "WICHTIG: Wettkampf in {$daysUntil} Tagen — Tapering-Phase! Kein hartes Training (kein interval, kein tempo_run). Nur easy_run oder rest.\n";
            }
        } else {
            $eventsText = "Keine kommenden Wettkämpfe.\n";
        }

        // Today's availability
        $availabilityText = '';
        if ($todayAvailability !== null) {
            if ($todayAvailability['available'] ?? false) {
                $maxMin = $todayAvailability['duration_min'] ?? null;
                $availabilityText = $maxMin
                    ? "Heutige Verfügbarkeit: maximal {$maxMin} Minuten. Passe duration_min entsprechend an.\n"
                    : "Heute verfügbar für Training.\n";
            } else {
                $availabilityText = "Heute laut Profil kein Training geplant. Empfehle rest.\n";
            }
        }

        $prompt = <<<PROMPT
Du bist ein präziser Lauf-Coach. Erstelle eine Trainingsempfehlung für heute als JSON-Objekt.

{$profileText}
{$activityText}
{$wellbeingText}
{$goalText}
{$eventsText}
{$taperWarning}
{$availabilityText}
Antworte NUR mit einem JSON-Objekt (kein Markdown, kein Text davor/danach):
{
  "type": "easy_run|tempo_run|interval|long_run|rest",
  "title": "Kurzer Titel der Einheit",
  "description": "2-3 Sätze Erklärung warum und wie. Nur auf bevorstehende Wettkämpfe eingehen, keine vergangenen Ereignisse erwähnen.",
  "distance_km": 8.0,
  "duration_min": 50,
  "pace_target": "5:30",
  "zone": 2,
  "intensity": "low|medium|high"
}
PROMPT;

        $content = $this->callOpenAI('recommendation', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Antworte ausschließlich mit dem angeforderten JSON-Objekt.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.4, 300);

        if (!$content) return null;

        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);
        $parsed  = json_decode(trim($content), true);

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Adjust an existing structured recommendation harder or softer.
     * Returns adjusted recommendation array or null on failure.
     */
    public function adjustTodayRecommendation(array $current, string $direction, ?array $runnerProfile, ?array $wellbeingEntry): ?array
    {
        $directionText = $direction === 'harder'
            ? 'Mache die Einheit HÄRTER: mehr Distanz (+15-25%), schnellere Pace, höhere Zone, oder Wechsel zu einem intensiveren Typ (z.B. easy_run → tempo_run).'
            : 'Mache die Einheit SOFTER: weniger Distanz (-15-25%), langsamere Pace, niedrigere Zone, oder Wechsel zu einem ruhigeren Typ (z.B. tempo_run → easy_run).';

        $wellbeingText = $wellbeingEntry ? "Wellbeing: Energie {$wellbeingEntry['energy_level']}/10, Muskelkater {$wellbeingEntry['muscle_soreness']}/10\n" : '';
        $profileText = $runnerProfile ? "Schwellenpace: {$runnerProfile['threshold_speed']} min/km\n" : '';

        $currentJson = json_encode($current, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Aktuelle Einheit:
{$currentJson}

{$profileText}{$wellbeingText}
Aufgabe: {$directionText}

Antworte NUR mit dem angepassten JSON-Objekt (gleiche Felder wie die Eingabe):
PROMPT;

        $content = $this->callOpenAI('adjust_recommendation', [
            ['role' => 'system', 'content' => 'Du bist ein Lauf-Coach. Antworte ausschließlich mit dem angeforderten JSON-Objekt.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.3, 300);

        if (!$content) return null;

        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);
        $parsed  = json_decode(trim($content), true);

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Generate a short daily motivational message from the coach.
     * Considers today's session type, upcoming events, and coach personality.
     * Returns a plain string (1–2 sentences) or null on failure.
     */
    public function generateDailyMessage(?string $sessionType, ?string $sessionTitle, ?array $upcomingEvents): ?string
    {
        $sessionText = $sessionType
            ? "Heutige geplante Einheit: {$sessionTitle} (Typ: {$sessionType}).\n"
            : "Heute kein spezifisches Training geplant.\n";

        $eventsText = '';
        if (!empty($upcomingEvents)) {
            $nearest = $upcomingEvents[0];
            $eventsText = "Nächster Wettkampf: {$nearest['name']} in {$nearest['days_until']} Tagen.\n";
        }

        $prompt = <<<PROMPT
{$sessionText}{$eventsText}
Schreib eine kurze, motivierende Botschaft (1–2 Sätze) für den Läufer für heute. Sprich ihn direkt an (du). Kein Emoji, keine Anführungszeichen. Nur den reinen Text.
PROMPT;

        $content = $this->callOpenAI('daily_message', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Du bist ein ermutigender Lauf-Coach. Antworte nur mit dem reinen Motivationstext.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.8, 100);

        return $content ? trim($content) : null;
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

        $text = $this->callOpenAI('threshold_pace', [
            ['role' => 'system', 'content' => 'Du bist ein präziser Sportwissenschaftler. Antworte ausschließlich mit JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.1, 50);

        if ($text && preg_match('/\{.*?\}/s', $text, $matches)) {
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

        $text = $this->callOpenAI('profile_estimation', [
            ['role' => 'system', 'content' => 'Du bist ein präziser Sportwissenschaftler. Antworte ausschließlich mit validem JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.2, 80);

        if ($text && preg_match('/\{.*?\}/s', $text, $matches)) {
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
        array $pastPlanResults = [],
        array $otherEvents = [],
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

        // Pre-compute per-date availability for each day in the plan window
        $isoToWeekday = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
        $perDateLines = [];
        $planWindowDays = min(21, $daysUntil + 1); // cover every day up to race, max 21
        for ($i = 0; $i < $planWindowDays; $i++) {
            $date    = now()->addDays($i);
            $dateStr = $date->format('Y-m-d');
            if ($dateStr > $eventDate) break;
            $dayKey    = $isoToWeekday[$date->isoWeekday()];
            $dayLabel  = $dayNames[$dayKey];
            // Override takes precedence over weekly default
            if (isset($availabilityOverrides[$dateStr])) {
                $ov = $availabilityOverrides[$dateStr];
                if (! ($ov['available'] ?? true)) {
                    $perDateLines[] = "- {$dateStr} ({$dayLabel}): ❌ NICHT VERFÜGBAR → type=\"rest\" PFLICHT";
                } else {
                    $max = (int) ($ov['duration_min'] ?? 0);
                    $perDateLines[] = "- {$dateStr} ({$dayLabel}): ✅ verfügbar, max. {$max} min";
                }
            } elseif ($weeklyAvailability) {
                $dayAvail = $weeklyAvailability[$dayKey] ?? null;
                if (! $dayAvail || ! ($dayAvail['available'] ?? false)) {
                    $perDateLines[] = "- {$dateStr} ({$dayLabel}): ❌ NICHT VERFÜGBAR → type=\"rest\" PFLICHT";
                } else {
                    $max = (int) ($dayAvail['duration_min'] ?? 0);
                    $perDateLines[] = "- {$dateStr} ({$dayLabel}): ✅ verfügbar, max. {$max} min";
                }
            }
        }
        $perDateAvailText = ! empty($perDateLines)
            ? "\n\n**BINDENDE Verfügbarkeit je Datum (Vorrang vor allen anderen Regeln):**\n"
                . implode("\n", $perDateLines)
                . "\nAlle Daten mit ❌ MÜSSEN type=\"rest\" erhalten — keine Ausnahmen!"
            : '';

        // Past race results (for learning from previous plan cycles)
        $pastResultsText = 'Keine vergangenen Rennergebnisse vorhanden.';
        if (! empty($pastPlanResults)) {
            $lines = [];
            foreach ($pastPlanResults as $r) {
                $stars  = $r['overall_rating'] ? str_repeat('⭐', $r['overall_rating']) : '–';
                $actual = $r['actual_time'] ?? 'nicht eingetragen';
                $diff   = '';
                // Simple time comparison for feedback
                if ($r['actual_time'] && $r['target_time']) {
                    [$th, $tm] = explode(':', $r['target_time']) + [0, 0];
                    [$ah, $am] = explode(':', $r['actual_time']) + [0, 0];
                    $targetSec = ((int)$th * 60 + (int)$tm) * 60;
                    $actualSec = ((int)$ah * 60 + (int)$am) * 60;
                    $deltaSec  = $actualSec - $targetSec;
                    if ($deltaSec <= 0) {
                        $diff = ' ✅ Ziel erreicht (' . abs((int)($deltaSec / 60)) . ' Min schneller)';
                    } else {
                        $diff = ' ❌ Ziel verfehlt (+' . (int)($deltaSec / 60) . ' Min langsamer)';
                    }
                }
                $note  = $r['result_notes'] ? " | Notiz: \"{$r['result_notes']}\"" : '';
                $lines[] = "- {$r['event_name']} ({$r['race_distance']}): Ziel {$r['target_time']} → Ergebnis {$actual}{$diff} | Plan-Bewertung: {$stars}{$note}";
            }
            $pastResultsText = "Vergangene Rennergebnisse des Athleten:\n" . implode("\n", $lines);
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

        // Other events in the plan window
        $otherEventsText = '';
        if (! empty($otherEvents)) {
            $lines = [];
            foreach ($otherEvents as $e) {
                $lines[] = "- {$e['date']}: {$e['name']} ({$e['distance']}, Priorität {$e['priority']})";
            }
            $otherEventsText = "\n\n**Weitere Rennevents im Planungszeitraum (an diesen Tagen KEIN Training — type=\"rest\"):**\n" . implode("\n", $lines);
        }

        // Per-date overrides are already included in $perDateAvailText above

        $totalDays = min(21, $daysUntil + 1); // number of entries the AI must produce

        $prompt = <<<PROMPT
Du bist ein professioneller Lauf-Coach. Erstelle einen Trainingsplan von heute bis zum Renntag.

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

**{$pastResultsText}**

**Bisherige Einheitsbewertungen (Athleten-Feedback):**
{$ratingsText}

**{$availabilityText}**{$otherEventsText}{$perDateAvailText}

**Planungsregeln:**
- Starte den Plan ab heute ({$today})
- Der letzte Tag im Plan ist IMMER der Renntag ({$eventDate}) — niemals danach
- Plane GENAU jeden Tag von {$today} bis {$eventDate} — das sind {$totalDays} Tage
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
- Lerne aus vergangenen Rennergebnissen: Ziel verfehlt → mehr spezifisches Tempotraining für diese Distanz; Ziel erreicht/übertroffen → Plan funktioniert, ähnliche Struktur beibehalten
- ANDERE RENNEVENTS: An Tagen mit anderen Rennevents im Planungszeitraum IMMER type="rest" — der Athlet läuft ein Rennen, kein zusätzliches Training.
- VERFÜGBARKEIT: Plane Training AUSSCHLIESSLICH an verfügbaren Tagen. An nicht verfügbaren Tagen IMMER type="rest". Die Trainingsdauer darf die angegebene Maximalzeit NIEMALS überschreiten. Tages-Ausnahmen haben Vorrang.

**Antworte ausschließlich mit einem JSON-Array mit GENAU {$totalDays} Einträgen — einen pro Tag von heute ({$today}) bis zum Renntag ({$eventDate}). Kein Tag darf fehlen. Ruhetage MÜSSEN als Eintrag mit type="rest" enthalten sein.**
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

        $text = $this->callOpenAI('event_plan', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Antworte ausschließlich mit einem validen JSON-Array ohne zusätzlichen Text.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.6, 2500, 60);

        if ($text && preg_match('/\[.*\]/s', $text, $matches)) {
            $sessions = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($sessions) && count($sessions) > 0) {
                Log::info('Event training plan generated', ['event_id' => $event->id, 'sessions' => count($sessions)]);
                return $sessions;
            }
        }

        Log::warning('Event training plan parse failed', ['text' => $text]);
        return null;
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

        $text = $this->callOpenAI('adjust_session', [
            ['role' => 'system', 'content' => 'Du bist ein präziser Lauf-Coach. Antworte ausschließlich mit validem JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.4, 300);

        if ($text && preg_match('/\{.*\}/s', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }
        return null;
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
Max. 3 Punkte pro Abschnitt. Konkrete Mengen, Produkte, Zeitangaben. Kein Allgemeinwissen. Alle Texte auf Deutsch.
PROMPT;

        $text = $this->callOpenAI('nutrition', [
            ['role' => 'system', 'content' => 'Du bist ein Ernährungs- und Laufexperte. Antworte ausschließlich mit validem JSON. Alle Texte im JSON müssen auf Deutsch sein.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.5, 600);

        if ($text && preg_match('/\{.*\}/s', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }
        return null;
    }

    /**
     * Generate a weekly review for the athlete (runs every Monday, cached in DB).
     */
    public function generateWeeklyReview(\App\Models\User $user, string $weekStart, string $weekEnd): ?string
    {
        // Strava activities this week (ground truth – one entry per actual workout)
        $activities = $user->activities()
            ->whereBetween(\DB::raw('DATE(start_date)'), [$weekStart, $weekEnd])
            ->orderBy('start_date')
            ->get();

        // Build a map: strava activity_id → linked training session (for rating/RPE)
        $linkedActivityIds = $activities->pluck('id')->filter()->all();
        $sessionsByActivity = \App\Models\TrainingSession::where('user_id', $user->id)
            ->whereIn('activity_id', $linkedActivityIds)
            ->get()
            ->keyBy('activity_id');

        // Skipped plan sessions (no Strava activity for that day)
        $skipped = \App\Models\TrainingSession::where('user_id', $user->id)
            ->where('status', 'skipped')
            ->whereBetween('planned_date', [$weekStart, $weekEnd])
            ->count();

        // Wellbeing this week
        $wellbeing = $user->wellbeingEntries()
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->get();

        if ($activities->isEmpty() && $wellbeing->isEmpty()) {
            return null; // Nothing to review
        }

        // Build session summary from Strava activities (correct names, no duplicates)
        $totalKm  = $activities->sum(fn ($a) => ($a->distance ?? 0) / 1000);
        $totalMin = $activities->sum(fn ($a) => round(($a->moving_time ?? 0) / 60));

        $sessionLines = $activities->map(function ($a) use ($sessionsByActivity) {
            $session = $sessionsByActivity[$a->id] ?? null;
            $km  = number_format(($a->distance ?? 0) / 1000, 1);
            $min = round(($a->moving_time ?? 0) / 60);
            $day = $a->start_date->format('D');
            $extra = '';
            if ($session) {
                if ($session->rating)           $extra .= ", Bewertung: {$session->rating}/5⭐";
                if ($session->effort_perceived) $extra .= ", RPE {$session->effort_perceived}/10";
            }
            return "- [{$day}] {$a->name}: {$km} km, {$min} min{$extra}";
        })->implode("\n");

        $wellbeingLines = '';
        if ($wellbeing->isNotEmpty()) {
            $avgE = round($wellbeing->avg('energy_level'), 1);
            $avgS = round($wellbeing->avg('sleep_quality'), 1);
            $avgM = round($wellbeing->avg('muscle_soreness'), 1);
            $sick = $wellbeing->where('is_sick', true)->count();
            $wellbeingLines = "Wellbeing Ø: Energie {$avgE}/10 | Schlaf {$avgS}/10 | Muskelkater {$avgM}/10" . ($sick > 0 ? " | {$sick} Krankheitstage" : '');
        }

        $totalKmFormatted = number_format($totalKm, 1);

        $prompt = <<<PROMPT
Du bist Lauf-Coach. Schreibe einen kurzen, motivierenden Wochenrückblick für deinen Athleten. Sei direkt, konkret und ehrlich — weder überschwänglich noch demotivierend.

**Trainingswoche {$weekStart} – {$weekEnd}:**
{$sessionLines}
Übersprungene Einheiten: {$skipped}
Gesamt: {$totalKmFormatted} km / {$totalMin} min

{$wellbeingLines}

**Dein Review (max. 150 Wörter):**
- Was lief gut diese Woche?
- Was fiel auf (Belastung, Wellbeing, Konstanz)?
- Eine konkrete Empfehlung für die kommende Woche

Schreibe fließend, kein JSON, kein Markdown mit #-Überschriften. Direkte Ansprache (du).
PROMPT;

        $this->forUser($user->id);

        $text = $this->callOpenAI('weekly_review', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Antworte auf Deutsch, kurz und präzise.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.7, 300, 30);

        return ($text && trim($text) !== '') ? trim($text) : null;
    }

    /**
     * Generate a structured step list for a planned training session.
     * Steps include warmup, work intervals (with repetitions), rest, and cooldown.
     * Returns array of step objects or null on failure.
     */
    public function generateSessionSteps(\App\Models\TrainingSession $session): ?array
    {
        $typeLabel = [
            'interval'  => 'Intervalltraining',
            'tempo_run' => 'Tempolauf',
            'easy_run'  => 'Lockerer Lauf',
            'long_run'  => 'Langer Lauf',
            'race_prep' => 'Rennvorbereitung',
        ][$session->type] ?? $session->type;

        $distKm = $session->distance_km ? "{$session->distance_km} km" : 'nicht angegeben';
        $durMin = $session->duration_min ? "{$session->duration_min} min" : 'nicht angegeben';
        $pace   = ($session->pace_target && $session->pace_target !== 'null') ? "{$session->pace_target} min/km" : 'kein Ziel';
        $zone   = $session->zone ? "Zone {$session->zone}" : 'nicht angegeben';
        $desc   = $session->description ? "\nBeschreibung: {$session->description}" : '';

        $prompt = <<<PROMPT
Du bist ein präziser Lauf-Coach. Erstelle eine strukturierte Workout-Schritteliste für die folgende Trainingseinheit.

Einheit: {$typeLabel} – {$session->title}
Distanz: {$distKm} | Dauer: {$durMin} | Ziel-Pace: {$pace} | {$zone}{$desc}

Regeln:
- Aufwärmen (warmup), Hauptteil, Auslaufen (cooldown) immer enthalten
- Für Intervalle: work + rest Steps mit gleichem "repetitions"-Wert für beide
- "repetitions" = Anzahl Wiederholungen des Intervallpaares (work+rest)
- Für Dauertempo/Easy-Läufe: work ohne repetitions (null)
- Pace bei rest-Phasen: null
- Maximal 6 Schritte

Antworte NUR mit einem JSON-Array:
[
  {"type": "warmup",   "label": "Einlaufen",  "duration_min": 10, "pace_target": "6:00", "zone": 1, "repetitions": null},
  {"type": "work",     "label": "Hartphase",  "duration_min": 5,  "pace_target": "4:10", "zone": 4, "repetitions": 3},
  {"type": "rest",     "label": "Trabpause",  "duration_min": 2,  "pace_target": null,   "zone": 1, "repetitions": 3},
  {"type": "cooldown", "label": "Auslaufen",  "duration_min": 8,  "pace_target": "6:30", "zone": 1, "repetitions": null}
]
PROMPT;

        $text = $this->callOpenAI('session_steps', [
            ['role' => 'system', 'content' => 'Du bist ein präziser Lauf-Coach. Antworte ausschließlich mit validem JSON-Array ohne zusätzlichen Text.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.4, 400, 25);

        if ($text && preg_match('/\[.*\]/s', $text, $matches)) {
            $steps = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($steps) && count($steps) > 0) {
                return $steps;
            }
        }
        return null;
    }

    /**
     * Generate a short celebratory PR message from the coach.
     */
    public function generatePrMessage(\App\Models\Activity $activity): ?string
    {
        $km      = number_format(($activity->distance ?? 0) / 1000, 2);
        $min     = round(($activity->moving_time ?? 0) / 60);
        $h       = (int) ($min / 60);
        $m       = $min % 60;
        $timeStr = $h > 0 ? "{$h}h {$m}min" : "{$m}min";
        $name    = $activity->name;

        $prompt = "Der Athlet hat gerade einen neuen persönlichen Rekord aufgestellt: \"{$name}\" – {$km} km in {$timeStr}. " .
                  "Schreib eine kurze, enthusiastische Glückwunschbotschaft als Coach (2–3 Sätze, direkte Anrede, auf Deutsch, mit passendem Emoji).";

        return $this->callOpenAI('coach_pr', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Du bist ein begeisterter Lauf-Coach. Feiere echte Leistungen deines Athleten.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.9, 150, 20);
    }

    /**
     * Conversational chat with the user's coach.
     * $history = [{role, content}, ...] (last N messages before the new one)
     * $newMessage = the user's latest message
     */
    public function chatWithCoach(\App\Models\User $user, array $history, string $newMessage): ?string
    {
        $today = now()->toDateString();

        // Today's planned training session (most important context)
        $todaySession = \App\Models\TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $today)
            ->where('status', '!=', 'skipped')
            ->orderBy('sort_order')
            ->first();

        // Upcoming sessions (next 5 days)
        $upcomingSessions = \App\Models\TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', '>', $today)
            ->whereDate('planned_date', '<=', now()->addDays(5)->toDateString())
            ->where('type', '!=', 'rest')
            ->orderBy('planned_date')
            ->limit(4)
            ->get();

        // Recent activities (last 5)
        $recentActivities = $user->activities()
            ->orderByDesc('start_date')
            ->limit(5)
            ->get()
            ->map(fn ($a) => sprintf(
                '- %s: %s (%s km)',
                $a->start_date->format('d.m.'),
                $a->name,
                number_format(($a->distance ?? 0) / 1000, 1)
            ))
            ->implode("\n");

        // Active goal
        $activeGoal = $user->goals()
            ->where('active', true)
            ->where('end_date', '>=', $today)
            ->orderBy('end_date')
            ->first();

        // Nearest upcoming event
        $upcomingEvent = $user->events()
            ->where('event_date', '>=', $today)
            ->orderBy('event_date')
            ->first();

        // Today's wellbeing
        $todayWellbeing = $user->wellbeingEntries()
            ->whereDate('date', $today)
            ->first();

        // Runner profile
        $profile = $user->runnerProfile;

        // Build context block — training plan comes first
        $ctx = [];

        if ($todaySession) {
            if ($todaySession->type === 'rest') {
                $status = $todaySession->status === 'completed' ? ' (bereits als erledigt markiert)' : '';
                $ctx[] = "Heutige geplante Trainingseinheit{$status}: Ruhetag";
            } else {
                $details = "Typ: {$todaySession->type}, Titel: \"{$todaySession->title}\"";
                if ($todaySession->distance_km) $details .= ", Distanz: {$todaySession->distance_km} km";
                if ($todaySession->duration_min) $details .= ", Dauer: {$todaySession->duration_min} min";
                if ($todaySession->pace_target)  $details .= ", Pace-Ziel: {$todaySession->pace_target} min/km";
                if ($todaySession->zone)         $details .= ", Zone: {$todaySession->zone}";
                if ($todaySession->intensity)    $details .= ", Intensität: {$todaySession->intensity}";
                $status = $todaySession->status === 'completed' ? ' (bereits absolviert)' : '';
                $desc = $todaySession->description ? "\n  Details: {$todaySession->description}" : '';
                $ctx[] = "Heutige geplante Trainingseinheit{$status}:\n  {$details}{$desc}";
            }
        } else {
            $ctx[] = "Heutige geplante Trainingseinheit: Kein Training im Plan für heute.";
        }

        if ($upcomingSessions->isNotEmpty()) {
            $lines = $upcomingSessions->map(fn ($s) => sprintf(
                '- %s: %s (%s%s)',
                $s->planned_date->format('d.m.'),
                $s->title,
                $s->type,
                $s->distance_km ? ", {$s->distance_km} km" : ''
            ))->implode("\n");
            $ctx[] = "Nächste geplante Einheiten:\n{$lines}";
        }

        if ($recentActivities) {
            $ctx[] = "Letzte abgeschlossene Aktivitäten:\n{$recentActivities}";
        }
        if ($activeGoal) {
            $ctx[] = "Aktives Ziel: {$activeGoal->name} (bis {$activeGoal->end_date->format('d.m.Y')})";
        }
        if ($upcomingEvent) {
            $days = (int) now()->diffInDays($upcomingEvent->event_date, false);
            $ctx[] = "Nächstes Event: {$upcomingEvent->name} am {$upcomingEvent->event_date->format('d.m.Y')} (in {$days} Tagen)";
        }
        if ($todayWellbeing) {
            $ctx[] = "Heutiges Wellbeing: Energie {$todayWellbeing->energy_level}/10, Schlaf {$todayWellbeing->sleep_quality}/10, Stimmung {$todayWellbeing->mood}/10";
        }
        if ($profile && $profile->threshold_speed) {
            $profileLine = "Schwellenpace: {$profile->threshold_speed} min/km";
            if ($profile->threshold_heart_rate) $profileLine .= ", LTHR: {$profile->threshold_heart_rate} bpm";
            $ctx[] = "Athletenprofil: {$profileLine}";
        }

        $contextBlock = "\n\nAktueller Kontext des Athleten (Heute: {$today}):\n" . implode("\n\n", $ctx);

        $systemPrompt = $this->buildSystemPrompt(
            "Du bist ein persönlicher Lauf-Coach. Antworte immer auf Deutsch. Sei persönlich, direkt und motivierend. " .
            "Halte Antworten prägnant (2–4 Sätze), außer wenn konkrete Pläne oder Erklärungen verlangt werden. " .
            "Sprich den Athleten direkt mit 'du' an. " .
            "WICHTIG: Stütze deine Antworten IMMER auf die Systemdaten im Kontext (Trainingsplan, Aktivitäten, Events, Wellbeing). " .
            "Wenn heute eine konkrete Trainingseinheit geplant ist, beziehe dich auf DIESE Einheit — empfehle niemals etwas Gegensätzliches.{$contextBlock}"
        );

        // Build message array: system + history + new user message
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $newMessage];

        return $this->callOpenAI('coach_chat', $messages, 0.8, 500, 45);
    }

    private function formatSeconds(int $seconds): string
    {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}


