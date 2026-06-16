<?php

namespace App\Services;

use App\Models\AiLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    protected string $apiKey;
    protected string $model;
    protected string $modelMini;
    protected string $baseUrl = 'https://api.openai.com/v1';
    protected ?string $coachPersonality = null;
    protected ?int $userId = null;

    public function __construct()
    {
        $this->apiKey     = config('services.openai.api_key');
        $this->model      = config('services.openai.model',      'gpt-5.5-2026-04-23');
        $this->modelMini  = config('services.openai.model_mini', 'gpt-5.4-mini');
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

    public function isRateLimited(): bool
    {
        if (!$this->userId) return false;
        $user  = User::find($this->userId);
        if (!$user) return false;
        $limit = $user->ai_daily_limit ?? 20;
        return AiLog::todayCountForUser($this->userId) >= $limit;
    }

    public function todayUsage(): array
    {
        if (!$this->userId) return ['used' => 0, 'limit' => 20];
        $user  = User::find($this->userId);
        $limit = $user?->ai_daily_limit ?? 20;
        return ['used' => AiLog::todayCountForUser($this->userId), 'limit' => $limit];
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
        string  $callType,
        array   $messages,
        float   $temperature,
        int     $maxTokens,
        int     $timeout = 30,
        ?string $model   = null
    ): ?string {
        $model   = $model ?? $this->model;
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
                'model'                 => $model,
                'messages'              => $messages,
                'max_completion_tokens' => $maxTokens,
            ]);

            $durationMs   = (int) round(microtime(true) * 1000) - $startMs;
            $body         = $response->json() ?? [];
            $content      = data_get($body, 'choices.0.message.content', '');
            $finishReason = data_get($body, 'choices.0.finish_reason');
            $usage        = $body['usage'] ?? [];
            $promptTok    = $usage['prompt_tokens']     ?? 0;
            $compTok      = $usage['completion_tokens'] ?? 0;
            $totalTok     = $usage['total_tokens']      ?? ($promptTok + $compTok);
            $cost         = AiLog::calculateCost($model, $promptTok, $compTok);

            // A 200 response can still be unusable: a reasoning model may burn the
            // entire max_completion_tokens budget on internal reasoning and return
            // empty content (finish_reason "length"). Treat that as a failure so it
            // shows up in the AI log instead of a misleading "success".
            $failed          = $response->failed();
            $emptyCompletion = ! $failed && trim((string) $content) === '';

            AiLog::create([
                'user_id'          => $this->userId,
                'call_type'        => $callType,
                'model'            => $model,
                'prompt_tokens'    => $promptTok,
                'completion_tokens'=> $compTok,
                'total_tokens'     => $totalTok,
                'cost_eur'         => $cost,
                'duration_ms'      => $durationMs,
                'prompt_preview'   => mb_substr($userContent, 0, 500),
                'response_preview' => mb_substr($content, 0, 500),
                'full_prompt'      => $userContent,
                'full_response'    => $content,
                'status'           => ($failed || $emptyCompletion) ? 'error' : 'success',
                'error_message'    => match (true) {
                    $failed          => 'HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500),
                    $emptyCompletion => "Leere Antwort (finish_reason: " . ($finishReason ?? 'unbekannt') . ", completion_tokens: {$compTok}) – Token-Budget vermutlich durch Reasoning erschöpft, max_completion_tokens erhöhen.",
                    default          => null,
                },
            ]);

            if ($failed) {
                Log::error("OpenAI {$callType} Error", ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            if ($emptyCompletion) {
                Log::warning("OpenAI {$callType} empty completion", ['finish_reason' => $finishReason, 'completion_tokens' => $compTok]);
                return null;
            }

            return $content;

        } catch (\Exception $e) {
            $durationMs = (int) round(microtime(true) * 1000) - $startMs;

            AiLog::create([
                'user_id'       => $this->userId,
                'call_type'     => $callType,
                'model'         => $model,
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
        ], 0.7, 900, 30, $this->modelMini);

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
        ], 0.7, 1200, 30, $this->modelMini);

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
            ], 0.2, 1000, 30, $this->modelMini);

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
    /**
     * Build a German weather context block for coaching prompts.
     * Returns '' when no weather is available so prompts stay clean.
     */
    private function weatherContext(?array $weather, bool $coachingHint = true): string
    {
        if (! $weather || ! isset($weather['temp_c'])) {
            return '';
        }

        $parts = ["{$weather['description']}, {$weather['temp_c']}°C"];
        if (($weather['apparent_c'] ?? null) !== null && $weather['apparent_c'] !== $weather['temp_c']) {
            $parts[] = "gefühlt {$weather['apparent_c']}°C";
        }
        if (($weather['precip_prob'] ?? null) !== null) {
            $parts[] = "Regenwahrscheinlichkeit {$weather['precip_prob']}%";
        }
        if (($weather['wind_kmh'] ?? null) !== null) {
            $parts[] = "Wind {$weather['wind_kmh']} km/h";
        }
        $line = implode(', ', $parts);

        $hint = $coachingHint
            ? ' Berücksichtige das in der Empfehlung (bei Hitze >25°C langsamere Pace, Hydration, frühere Tageszeit; bei Kälte <2°C längeres Aufwärmen/Kleidung; bei Regen/Sturm/Gewitter Vorsicht oder Indoor-Alternative).'
            : '';

        return "Wetter heute am Trainingsort: {$line}.{$hint}\n";
    }

    public function generateTodayRecommendation(?array $runnerProfile, ?array $yesterdayActivity, ?array $wellbeingEntry, ?array $goal, array $progress, array $upcomingEvents = [], ?array $todayAvailability = null, ?array $weather = null, ?array $returnToRun = null): ?array
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
                $taperWarning = "WICHTIG: Wettkampf in {$daysUntil} Tagen — Tapering-Phase! Kein hartes Training (kein interval, kein tempo_run, kein progressive_run, kein test_run). Nur easy_run oder rest.\n";
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

        $weatherText = $this->weatherContext($weather);

        // Return-to-run build-up: today's session must respect the current step.
        $returnToRunText = '';
        if ($returnToRun && isset($returnToRun['step'])) {
            $label = $returnToRun['trigger_label'] ?? 'Pause';
            $step  = (int) $returnToRun['step'];
            $total = (int) ($returnToRun['total_steps'] ?? 5);
            if ($step >= $total) {
                $returnToRunText = "WIEDEREINSTIEG (nach {$label}): Stufe {$step}/{$total} — der Athlet ist zurück im Normalbetrieb, normale Intensität ist wieder möglich.\n";
            } else {
                $c = $returnToRun['current'] ?? [];
                $returnToRunText = "WICHTIG — WIEDEREINSTIEG nach {$label} (Stufe {$step} von {$total}): Der Athlet baut nach einer Pause behutsam wieder auf. "
                    . "Die heutige Empfehlung MUSS dieser Stufe entsprechen: type=\"" . ($c['type'] ?? 'easy_run') . "\", Zone " . ($c['zone'] ?? '1–2') . ", maximal " . ($c['max_min'] ?? 30) . " Minuten. "
                    . ($c['rule'] ?? '') . " Diese Vorgabe hat Vorrang vor anderen Überlegungen (außer ein Ruhetag ist nötig).\n";
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
{$returnToRunText}
{$weatherText}
Antworte NUR mit einem JSON-Objekt (kein Markdown, kein Text davor/danach):
{
  "type": "easy_run|tempo_run|interval|long_run|progressive_run|test_run|rest",
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
        ], 0.4, 1000, 30, $this->modelMini);

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
        ], 0.3, 1000, 30, $this->modelMini);

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
    public function generateDailyMessage(?string $sessionType, ?string $sessionTitle, ?array $upcomingEvents, ?array $weather = null): ?string
    {
        $sessionText = $sessionType
            ? "Heutige geplante Einheit: {$sessionTitle} (Typ: {$sessionType}).\n"
            : "Heute kein spezifisches Training geplant.\n";

        $eventsText = '';
        if (!empty($upcomingEvents)) {
            $nearest = $upcomingEvents[0];
            $eventsText = "Nächster Wettkampf: {$nearest['name']} in {$nearest['days_until']} Tagen.\n";
        }

        $weatherText = $this->weatherContext($weather, false);

        $prompt = <<<PROMPT
{$sessionText}{$eventsText}{$weatherText}
Schreib eine kurze, motivierende Botschaft (1–2 Sätze) für den Läufer für heute. Sprich ihn direkt an (du). Beziehe das Wetter nur ein, wenn es bemerkenswert ist (sehr warm, kalt, Regen, Sturm). Kein Emoji, keine Anführungszeichen. Nur den reinen Text.
PROMPT;

        $content = $this->callOpenAI('daily_message', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Du bist ein ermutigender Lauf-Coach. Antworte nur mit dem reinen Motivationstext.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.8, 700, 30, $this->modelMini);

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
        $text = $this->callOpenAI('threshold_pace', [
            ['role' => 'system', 'content' => 'Du bist ein präziser Sportwissenschaftler. Antworte ausschließlich mit JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.1, 3000);

        if ($text && preg_match('/\{.*?\}/s', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($json['threshold_pace'])) {
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
        ], 0.2, 1000, 60, $this->modelMini);

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
        array $finalizedSessions = [],
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

        // Finalized sessions (skipped / completed) — give the coach context, PHP will not overwrite them
        $finalizedText = '';
        if (! empty($finalizedSessions)) {
            $lines = [];
            foreach ($finalizedSessions as $s) {
                $statusLabel = $s['status'] === 'skipped' ? 'Übersprungen' : 'Absolviert';
                $reason      = ! empty($s['skip_reason']) ? " (Grund: {$s['skip_reason']})" : '';
                $lines[]     = "- {$s['date']}: {$s['type']} — {$statusLabel}{$reason}";
            }
            $finalizedText = "\n\n**Bereits abgeschlossene Einheiten (nur als Kontext — du musst diese Tage NICHT im Array zurückgeben):**\n"
                . implode("\n", $lines)
                . "\nPasse die Folgetage entsprechend an (z.B. Erschöpfung → morgen leichter planen).";
        }

        // Recovery detection: illness / injury / exhaustion / poor wellbeing in the last 7 days
        $recoveryWarning = '';
        $recoveryTrigger = null; // 'sick' | 'injured' | 'exhausted' | 'poor_wellbeing'
        $recoveryDetails = [];

        // Check wellbeing entries (last 7 days)
        $last7Wellbeing = array_slice($wellbeingData, 0, 7);
        foreach ($last7Wellbeing as $w) {
            if (! empty($w['is_sick'])) {
                $recoveryTrigger = 'sick';
                $recoveryDetails[] = "krank am {$w['date']}";
                break;
            }
            if (! empty($w['is_injured'])) {
                $recoveryTrigger = 'injured';
                $recoveryDetails[] = "verletzt am {$w['date']}";
                break;
            }
        }

        // Check skipped sessions (last 7 days) for illness/injury/exhaustion reasons
        if ($recoveryTrigger === null) {
            $sevenDaysAgo = now()->subDays(7)->format('Y-m-d');
            foreach ($finalizedSessions as $s) {
                if (($s['status'] ?? '') !== 'skipped') continue;
                if (($s['date'] ?? '') < $sevenDaysAgo) continue;
                $reason = mb_strtolower($s['skip_reason'] ?? '');
                if (str_contains($reason, 'krank') || str_contains($reason, 'sick')) {
                    $recoveryTrigger = 'sick';
                    $recoveryDetails[] = "Krank-Skip am {$s['date']}";
                    break;
                }
                if (str_contains($reason, 'verletzt') || str_contains($reason, 'injur')) {
                    $recoveryTrigger = 'injured';
                    $recoveryDetails[] = "Verletzt-Skip am {$s['date']}";
                    break;
                }
                if (str_contains($reason, 'erschöpft') || str_contains($reason, 'erschopft') || str_contains($reason, 'exhausted')) {
                    $recoveryTrigger = 'exhausted';
                    $recoveryDetails[] = "Erschöpft-Skip am {$s['date']}";
                    break;
                }
            }
        }

        // Check sustained poor wellbeing over last 7 days (even without sick/injured flag)
        if ($recoveryTrigger === null && count($last7Wellbeing) >= 3) {
            $avgEnergy   = array_sum(array_column($last7Wellbeing, 'energy'))   / count($last7Wellbeing);
            $avgSoreness = array_sum(array_column($last7Wellbeing, 'soreness')) / count($last7Wellbeing);
            $avgSleep    = array_sum(array_column($last7Wellbeing, 'sleep'))    / count($last7Wellbeing);
            $avgStress   = array_sum(array_column($last7Wellbeing, 'stress'))   / count($last7Wellbeing);

            if ($avgEnergy < 4) {
                $recoveryTrigger = 'poor_wellbeing';
                $recoveryDetails[] = sprintf('Ø Energie %.1f/10 (letzte 7 Tage)', $avgEnergy);
            }
            if ($avgSoreness > 7) {
                $recoveryTrigger = 'poor_wellbeing';
                $recoveryDetails[] = sprintf('Ø Muskelkater %.1f/10 (letzte 7 Tage)', $avgSoreness);
            }
            if ($avgSleep < 4) {
                $recoveryDetails[] = sprintf('Ø Schlaf %.1f/10 (letzte 7 Tage)', $avgSleep);
                if ($recoveryTrigger === null) $recoveryTrigger = 'poor_wellbeing';
            }
            if ($avgStress > 7) {
                $recoveryDetails[] = sprintf('Ø Stress %.1f/10 (letzte 7 Tage)', $avgStress);
                if ($recoveryTrigger === null) $recoveryTrigger = 'poor_wellbeing';
            }
        }

        if ($recoveryTrigger !== null) {
            $triggerLabel = match($recoveryTrigger) {
                'sick'          => 'Krankheit',
                'injured'       => 'Verletzung',
                'exhausted'     => 'starker Erschöpfung',
                'poor_wellbeing'=> 'anhaltend schlechtem Wellbeing',
            };
            $detailStr = empty($recoveryDetails) ? '' : "\nErkannte Signale: " . implode(', ', $recoveryDetails);

            $recoveryWarning = <<<WARN

⚠️ **PFLICHT-SICHERHEITSREGEL — Wiederaufnahme nach {$triggerLabel} (letzte 7 Tage):**{$detailStr}

MEDIZINISCHE WARNUNG: Nach Infekten, Verletzungen und starker Erschöpfung besteht erhöhtes Risiko einer Herzmuskelentzündung (Myokarditis) bei zu früher intensiver Belastung.

VERPFLICHTENDE STUFENREGEL — zähle TRAININGSEINHEITEN, nicht Kalendertage (Ruhetage zählen nicht):

Einheit 1 (erste Trainingseinheit nach der Pause):
- type="easy_run", Zone 1–2, max. 30 min, sehr lockeres Tempo
- intensity="low", KEIN Tempolauf, KEIN Intervall

Einheit 2 (zweite Trainingseinheit):
- type="easy_run", Zone 2, max. 40 min, lockeres Tempo
- intensity="low"

Ab Einheit 3 (dritte Trainingseinheit und danach):
- Schrittweise Steigerung erlaubt — z.B. tempo_run mit reduziertem Umfang
- Keine Intervalle oder Long Runs vor Einheit 4

Ab Einheit 5:
- Normale Intensität möglich

Ruhetage zwischen den Einheiten zählen NICHT — es geht um Trainingsbelastungen, nicht um Kalendertage.
Coach-Ton: Empathisch, fürsorglich, motivierend — Erholung ist Training.

WARN;
        }

        $totalDays = min(21, $daysUntil + 1); // number of entries the AI must produce

        if ($event->isBackyard()) {
            $targetYards  = (int) $event->target_yards;
            $targetDistKm = number_format($event->target_distance_km, 1, ',', '.');
            $lapKm        = number_format(\App\Models\Event::BACKYARD_LAP_KM, 3, ',', '.');

            $prompt = <<<PROMPT
Du bist ein erfahrener Ultra- und Backyard-Coach. Erstelle einen Trainingsplan von heute bis zum Renntag für einen **Backyard Ultra** (Last-One-Standing-Format).

**Format-Erklärung (wichtig für die Planung):**
- Eine Runde („Yard") = {$lapKm} km, die zu jeder vollen Stunde gestartet wird. Wer die Runde innerhalb der Stunde schafft, darf in die nächste Stunde — die Restzeit ist Pause.
- Es gibt KEINE Zielzeit und KEIN Tempo-Rennen. Wer zu schnell läuft, verbrennt unnötig Körner. Ziel ist: möglichst viele Stunden durchhalten und am Ende übrig bleiben.
- Erfolg = Ausdauer, Pacing-Disziplin (langsam genug für Pause), Verpflegung, Magen-Training, mentale Stärke und Umgang mit Müdigkeit/Dunkelheit.

**Event:**
- Name: {$event->name}
- Datum: {$eventDate} (in {$daysUntil} Tagen)
- Format: Backyard Ultra
- Ziel: {$targetYards} Yards / Stunden (≈ {$targetDistKm} km)
- Priorität: {$priorityText}

**{$profileText}**

**Letzte Aktivitäten (4 Wochen):**
{$activitiesText}

**{$wellbeingText}**

**{$loadText}**

**{$pastResultsText}**

**Bisherige Einheitsbewertungen (Athleten-Feedback):**
{$ratingsText}

**{$availabilityText}**{$otherEventsText}{$finalizedText}{$recoveryWarning}{$perDateAvailText}

**Planungsregeln (Backyard-spezifisch):**
- Starte den Plan ab heute ({$today})
- Plane GENAU jeden Tag von {$today} bis {$eventDate} — das sind {$totalDays} Tage. Der letzte Tag ist IMMER der Renntag.
- Am Renntag ({$eventDate}): type="race_prep", title="{$event->name}", beschreibe Renn-Strategie (langsames, konstantes Rundentempo, Verpflegung pro Runde, Pausen-Management).
- HAUPTFOKUS: hohes, lockeres aerobes Volumen (Zone 1–2). Tempo/Intervalle sind NICHT der limitierende Faktor — maximal selten und nur leicht.
- LONGRUNS: wöchentlich mind. ein langer, lockerer Lauf, schrittweise verlängert (time_on_feet zählt mehr als Tempo).
- BACK-TO-BACK (back_to_back_long): an aufeinanderfolgenden Tagen (z.B. Sa+So) zwei längere Läufe — trainiert das Laufen auf müden Beinen, zentral fürs Format. Mind. alle 1–2 Wochen in Build/Peak.
- TIME ON FEET (time_on_feet): lange, sehr lockere Einheiten mit bewusst niedrigem Tempo, ggf. Geh-Pausen — Dauer wichtiger als Distanz.
- YARD-SIMULATION (yard_simulation): mehrere {$lapKm}-km-Runden im echten Stundenrhythmus (Runde laufen, Rest der Stunde Pause, dann wieder los). Übt Rhythmus, Verpflegung und Pausen-Management. Etwa alle 2–3 Wochen in Build/Peak, NIE in den letzten 10 Tagen. Beschreibe Anzahl der Runden im description.
- NACHTLAUF (night_run): mind. ein Lauf in Dunkelheit/Abend zur Vorbereitung auf Schlafentzug und Nachtstunden — in der Build/Peak-Phase.
- VERPFLEGUNG: weise bei langen Einheiten ausdrücklich auf Ess-/Trink-Training (Magen-Training) hin.
- TAPER: in den letzten 10–14 Tagen Volumen deutlich reduzieren, aber etwas Time-on-Feet halten — ausgeruht und ohne Müdigkeit an den Start.
- Berücksichtige Wellbeing & Trainingsbelastung: schlechter Schlaf/hoher Stress oder TSB < −30 → leichtere Einheiten / mehr Ruhe.
- Mindestens ein Ruhetag pro Woche.
- VERFÜGBARKEIT: Plane Training AUSSCHLIESSLICH an verfügbaren Tagen. An nicht verfügbaren Tagen IMMER type="rest". Die Trainingsdauer darf die angegebene Maximalzeit NIEMALS überschreiten. Tages-Ausnahmen haben Vorrang.
- ANDERE RENNEVENTS: An Tagen mit anderen Rennevents im Planungszeitraum IMMER type="rest".

**Antworte ausschließlich mit einem JSON-Array — einen Eintrag pro offenem Tag von heute ({$today}) bis zum Renntag ({$eventDate}). Bereits abgeschlossene Tage (siehe oben) NICHT zurückgeben. Ruhetage MÜSSEN als Eintrag mit type="rest" enthalten sein.**
[
  {
    "date": "YYYY-MM-DD",
    "type": "rest|easy_run|long_run|back_to_back_long|time_on_feet|yard_simulation|night_run|progressive_run|race_prep",
    "title": "Kurzer Titel (max 40 Zeichen)",
    "description": "Beschreibung der Einheit (2-3 Sätze, konkrete Anweisungen inkl. Verpflegungshinweis bei langen Läufen)",
    "distance_km": 0,
    "duration_min": 0,
    "pace_target": "6:30-7:30 oder null bei Ruhetag",
    "zone": 2,
    "intensity": "rest|low|medium|high"
  }
]
Für Ruhetage: distance_km=0, duration_min=0, pace_target=null, zone=null.
PROMPT;
        } else {
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

**{$availabilityText}**{$otherEventsText}{$finalizedText}{$recoveryWarning}{$perDateAvailText}

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
- PROGRESSIVE LÄUFE (progressive_run): Lauf beginnt in Zone 1–2 und steigert sich Kilometer für Kilometer bis Zone 3–4 gegen Ende. Ideal für Tempoaufbau ohne volle Belastung. Max. 1× pro Woche, nur in Build- und Peak-Phase, nicht im Tapering.
- TESTLÄUFE (test_run): 5k oder 10k Zeitversuch bei maximalem persönlichen Effort (Zone 4–5) — so schnell wie möglich über die gesamte Distanz. Zweck: objektive Fortschrittsmessung und automatische Neukalibrierung der Schwellenpace. Plane exakt alle 4–6 Wochen — niemals in den letzten 14 Tagen vor dem A-Event. Nach einem test_run folgt IMMER ein easy_run als Regeneration. Kündige den Testlauf im title-Feld deutlich an, z.B. "5k Zeitversuch".

**Antworte ausschließlich mit einem JSON-Array — einen Eintrag pro offenem Tag von heute ({$today}) bis zum Renntag ({$eventDate}). Bereits abgeschlossene Tage (siehe oben) NICHT zurückgeben. Ruhetage MÜSSEN als Eintrag mit type="rest" enthalten sein.**
[
  {
    "date": "YYYY-MM-DD",
    "type": "rest|easy_run|tempo_run|interval|long_run|progressive_run|test_run|race_prep",
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
        }

        $text = $this->callOpenAI('event_plan', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Antworte ausschließlich mit einem validen JSON-Array ohne zusätzlichen Text.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.6, 6000, 120);

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
        ], 0.4, 1000, 30, $this->modelMini);

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
        ], 0.5, 1500, 30, $this->modelMini);

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
        ], 0.7, 1000, 60, $this->modelMini);

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
            'interval'       => 'Intervalltraining',
            'tempo_run'      => 'Tempolauf',
            'easy_run'       => 'Lockerer Lauf',
            'long_run'       => 'Langer Lauf',
            'race_prep'      => 'Rennvorbereitung',
            'progressive_run'=> 'Progressiver Lauf',
            'test_run'       => 'Testlauf (Zeitversuch)',
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
            ? "ZEITBUDGET (ABSOLUT VERBINDLICH): Gesamtdauer = {$totalMin} Minuten.\n" .
              "Rechnung: warmup + (work × reps) + (rest × reps) + cooldown = {$totalMin}\n" .
              "Empfehlung: Aufwärmen ~{$warmupMin} min, Hauptteil ~{$mainMin} min gesamt, Auslaufen ~{$cooldownMin} min.\n" .
              "Prüfe deine Rechnung bevor du antwortest!"
            : "Wähle eine sinnvolle Gesamtdauer.";

        $prompt = <<<PROMPT
Du bist ein präziser Lauf-Coach. Erstelle eine strukturierte Workout-Schritteliste.

Einheit: {$typeLabel} – {$session->title}
Distanz: {$distKm} | Dauer: {$totalMin} min | Pace-Ziel: {$pace} | {$zone}{$desc}{$thresholdLine}

{$durationRule}

Regeln:
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

        $text = $this->callOpenAI('session_steps', [
            ['role' => 'system', 'content' => 'Antworte ausschließlich mit validem JSON-Array ohne Text. duration_min sind ganze Zahlen ≥ 1.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.3, 1200, 45, $this->modelMini);

        if ($text && preg_match('/\[.*\]/s', $text, $matches)) {
            $steps = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($steps) && count($steps) > 0) {
                return $this->normalizeStepDurations($steps, $totalMin ?: null);
            }
        }
        return null;
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

        // Adjust the highest-contribution non-rest step
        $adjustIdx  = null;
        $maxContrib = 0;
        foreach ($steps as $i => $step) {
            if ($step['type'] === 'rest') continue;
            $contrib = $step['duration_min'] * max(1, (int)($step['repetitions'] ?? 1));
            if ($contrib > $maxContrib) { $maxContrib = $contrib; $adjustIdx = $i; }
        }

        if ($adjustIdx !== null) {
            $reps = max(1, (int)($steps[$adjustIdx]['repetitions'] ?? 1));
            $steps[$adjustIdx]['duration_min'] = max(1, $steps[$adjustIdx]['duration_min'] + (int)round(($targetMin - $total) / $reps));
        }

        return $steps;
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
        ], 0.9, 700, 30, $this->modelMini);
    }

    /**
     * Conversational chat with the user's coach.
     * $history = [{role, content}, ...] (last N messages before the new one)
     * $newMessage = the user's latest message
     */
    public function chatWithCoach(\App\Models\User $user, array $history, string $newMessage): ?string
    {
        $today = now()->toDateString();
        $profile = $user->runnerProfile;

        // Helper: Strava m/s → "M:SS min/km"
        $mpsToMSS = function (float $mps): string {
            if ($mps <= 0) return '—';
            $secPerKm = 1000 / $mps;
            return sprintf('%d:%02d', (int)($secPerKm / 60), (int)$secPerKm % 60);
        };

        // Helper: threshold_speed float (e.g. 5.5) → "5:30"
        $floatMinToMSS = function (?float $min): string {
            if (!$min) return '—';
            $m = (int)$min;
            $s = (int)round(($min - $m) * 60);
            return sprintf('%d:%02d', $m, $s);
        };

        // ── Runner profile ────────────────────────────────────────────────
        $profileLines = [];
        if ($profile) {
            if ($profile->threshold_speed) {
                $profileLines[] = 'Schwellenpace: ' . $floatMinToMSS($profile->threshold_speed) . ' min/km';
            }
            if ($profile->threshold_heart_rate) $profileLines[] = 'LTHR: ' . $profile->threshold_heart_rate . ' bpm';
            if ($profile->max_heart_rate)       $profileLines[] = 'Max HR: ' . $profile->max_heart_rate . ' bpm';

            if (!empty($profile->pace_zones)) {
                $zoneStr = collect($profile->pace_zones)->map(
                    fn ($r, $z) => "Z{$z}: " . $floatMinToMSS($r['min'] ?? null) . '–' . $floatMinToMSS($r['max'] ?? null)
                )->implode(' | ');
                if ($zoneStr) $profileLines[] = 'Pace-Zonen: ' . $zoneStr;
            }
        }

        // Running experience from first Strava run
        $firstRun = $user->activities()->where('type', 'Run')->oldest('start_date')->first();
        if ($firstRun) {
            $months = (int)$firstRun->start_date->diffInMonths(now());
            $since  = $months < 24 ? "{$months} Monate" : round($months / 12, 1) . ' Jahre';
            $profileLines[] = "Läuft seit: ca. {$since} (erste Aktivität: {$firstRun->start_date->format('M Y')})";
        }

        // ── Weekly km (last 4 calendar weeks) ────────────────────────────
        $weeklyLines = [];
        for ($w = 0; $w < 4; $w++) {
            $wStart = now()->startOfWeek()->subWeeks($w);
            $wEnd   = (clone $wStart)->addWeek();
            $km     = round($user->activities()
                ->where('type', 'Run')
                ->whereBetween('start_date', [$wStart, $wEnd])
                ->sum('distance') / 1000, 1);
            $label = match ($w) { 0 => 'Aktuelle Woche', 1 => 'Letzte Woche', default => "Vor {$w} Wochen" };
            $weeklyLines[] = "{$label}: {$km} km";
        }

        // ── Training distribution last 30 days (completed) ───────────────
        $typeMap = [
            'easy_run' => 'Lockere Läufe', 'tempo_run' => 'Tempoläufe',
            'interval' => 'Intervalle', 'long_run' => 'Lange Läufe',
            'progressive_run' => 'Progressive Läufe', 'test_run' => 'Testläufe',
            'race_prep' => 'Rennvorbereitung',
        ];
        $completedByType = \App\Models\TrainingSession::where('user_id', $user->id)
            ->where('status', 'completed')
            ->where('planned_date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('type, count(*) as cnt')
            ->groupBy('type')
            ->pluck('cnt', 'type')
            ->toArray();
        $distLines = array_map(
            fn ($type, $cnt) => ($typeMap[$type] ?? $type) . ': ' . $cnt . '×',
            array_keys($completedByType), $completedByType
        );

        // ── Recent runs (last 10) with full metrics ───────────────────────
        $recentRuns = $user->activities()
            ->where('type', 'Run')
            ->orderByDesc('start_date')
            ->limit(10)
            ->get()
            ->map(function ($a) use ($mpsToMSS) {
                $km    = number_format(($a->distance ?? 0) / 1000, 1);
                $pace  = $a->average_speed > 0 ? $mpsToMSS((float)$a->average_speed) . ' min/km' : '—';
                $dur   = $a->moving_time ? (int)round($a->moving_time / 60) . ' min' : '';
                $hr    = $a->average_heartrate ? (int)$a->average_heartrate . ' bpm' : '';
                $hrMax = $a->max_heartrate    ? '/ ' . (int)$a->max_heartrate . ' max' : '';
                $parts = array_filter([$km . ' km', $pace, $dur, trim($hr . ' ' . $hrMax)]);
                return '- ' . $a->start_date->format('d.m.') . ' "' . $a->name . '": ' . implode(' | ', $parts);
            })
            ->implode("\n");

        // ── Today's planned session ───────────────────────────────────────
        $todaySession = \App\Models\TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $today)
            ->where('status', '!=', 'skipped')
            ->orderBy('sort_order')
            ->first();

        // ── Upcoming sessions (next 7 days) ───────────────────────────────
        $upcomingSessions = \App\Models\TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', '>', $today)
            ->whereDate('planned_date', '<=', now()->addDays(7)->toDateString())
            ->where('type', '!=', 'rest')
            ->orderBy('planned_date')
            ->limit(5)
            ->get();

        // ── Upcoming events (all) ─────────────────────────────────────────
        $events = $user->events()
            ->where('event_date', '>=', $today)
            ->orderBy('event_date')
            ->limit(4)
            ->get();

        // ── Today's wellbeing ─────────────────────────────────────────────
        $todayWellbeing = $user->wellbeingEntries()->whereDate('date', $today)->first();

        // ── Assemble context sections ─────────────────────────────────────
        $ctx = [];

        if ($profileLines) {
            $ctx[] = "ATHLETENPROFIL:\n" . implode("\n", $profileLines);
        }

        if ($weeklyLines) {
            $ctx[] = "WOCHENKILOMETER:\n" . implode("\n", $weeklyLines);
        }

        if ($distLines) {
            $ctx[] = "TRAININGSVERTEILUNG (letzte 30 Tage, abgeschlossen):\n" . implode(', ', $distLines);
        }

        if ($recentRuns) {
            $ctx[] = "LETZTE LÄUFE (inkl. Pace & HR):\n{$recentRuns}";
        }

        if ($todaySession) {
            if ($todaySession->type === 'rest') {
                $s = $todaySession->status === 'completed' ? ' (bereits erledigt)' : '';
                $ctx[] = "HEUTIGES TRAINING{$s}: Ruhetag";
            } else {
                $d = "Typ: {$todaySession->type}, Titel: \"{$todaySession->title}\"";
                if ($todaySession->distance_km) $d .= ", {$todaySession->distance_km} km";
                if ($todaySession->duration_min) $d .= ", {$todaySession->duration_min} min";
                if ($todaySession->pace_target && $todaySession->pace_target !== 'null') $d .= ", Pace-Ziel: {$todaySession->pace_target} min/km";
                if ($todaySession->zone)         $d .= ", Zone {$todaySession->zone}";
                $s    = $todaySession->status === 'completed' ? ' (bereits absolviert)' : '';
                $desc = $todaySession->description ? "\n  Details: {$todaySession->description}" : '';
                $ctx[] = "HEUTIGES TRAINING{$s}:\n  {$d}{$desc}";
            }
        } else {
            $ctx[] = "HEUTIGES TRAINING: Kein Training geplant.";
        }

        if ($upcomingSessions->isNotEmpty()) {
            $lines = $upcomingSessions->map(fn ($s) => sprintf(
                '- %s: %s (%s%s)',
                $s->planned_date->format('d.m.'), $s->title, $s->type,
                $s->distance_km ? ", {$s->distance_km} km" : ''
            ))->implode("\n");
            $ctx[] = "NÄCHSTE EINHEITEN (7 Tage):\n{$lines}";
        }

        if ($events->isNotEmpty()) {
            $lines = $events->map(function ($e) {
                $days   = (int)now()->startOfDay()->diffInDays($e->event_date->copy()->startOfDay(), false);
                $priStr = match ($e->priority) { 'A' => '★ A-Event', 'B' => 'B-Event', default => 'C-Event' };
                $target = $e->target_time_formatted ? ", Ziel: {$e->target_time_formatted}" : '';
                return "- {$e->name} ({$e->distance_label}) – {$e->event_date->format('d.m.Y')} (in {$days} Tagen) [{$priStr}{$target}]";
            })->implode("\n");
            $ctx[] = "KOMMENDE EVENTS:\n{$lines}";
        }

        if ($todayWellbeing) {
            $ctx[] = "WELLBEING HEUTE: Energie {$todayWellbeing->energy_level}/10, Schlaf {$todayWellbeing->sleep_quality}/10, Stimmung {$todayWellbeing->mood}/10";
        }

        $contextBlock = "\n\n=== ATHLETEN-DATEN (Stand: {$today}) ===\n" . implode("\n\n", $ctx) . "\n=== ENDE ===";

        $coachName = $user->coach?->name ?? 'Coach';

        $systemPrompt = $this->buildSystemPrompt(
            "Du bist {$coachName}, der persönliche Lauf-Coach von {$user->name}. " .
            "Du kennst alle Trainingsdaten deines Athleten — Paces, Herzfrequenzen, Schwellenpace, Wochenkilometer, Events — und nutzt sie für präzise, datenbasierte Antworten wie ein echter Trainer, der seinen Athleten wirklich kennt. " .
            "Antworte immer auf Deutsch. Sprich den Athleten direkt mit 'du' an. " .
            "Passe die Antwortlänge der Frage an: Kurze Fragen → 1–3 Sätze. Analysefragen, Trainingsempfehlungen oder 'Was soll ich trainieren?' → ausführlich und strukturiert mit konkreten Zahlen aus den Daten (Paces, HR, km). " .
            "Nutze Markdown (Listen, Fettschrift, Tabellen) für strukturierte Antworten. " .
            "Wenn du für eine präzisere Antwort mehr Daten brauchst (z.B. Km-Splits, genaue Streckenbeschaffenheit), frag gezielt danach. " .
            "Stütze dich IMMER auf die echten Zahlen aus den Athleten-Daten — niemals auf generische Empfehlungen. " .
            "Wenn heute eine Trainingseinheit geplant ist, empfehle niemals etwas Gegensätzliches." .
            $contextBlock
        );

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $newMessage];

        return $this->callOpenAI('coach_chat', $messages, 0.8, 2500, 60);
    }

    /** Tool definitions for coach function calling. */
    private function coachTools(): array
    {
        return [
            ['type' => 'function', 'function' => [
                'name'        => 'remember_user_fact',
                'description' => 'Speichere eine wichtige Tatsache, Vorliebe oder Eigenschaft des Athleten dauerhaft im Profil – z.B. Vorlieben, Stärken/Schwächen, Verletzungshistorie, Trainingspräferenzen.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'fact' => ['type' => 'string', 'description' => 'Die zu merkende Information, prägnant formuliert'],
                ], 'required' => ['fact']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'modify_today_session',
                'description' => 'Ändere die heutige Trainingseinheit. Nutze dies bei "zu leicht", "mach es schwerer", "ich möchte heute Intervalle für 60 min" etc. Alle Felder sind optional – ändere nur was nötig.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'type'         => ['type' => 'string', 'enum' => ['easy_run','tempo_run','interval','long_run','progressive_run','test_run','race_prep'], 'description' => 'Trainingstyp'],
                    'title'        => ['type' => 'string', 'description' => 'Titel der Einheit'],
                    'description'  => ['type' => 'string', 'description' => 'Detaillierte Beschreibung des Workouts inkl. Intervallstruktur'],
                    'distance_km'  => ['type' => 'number', 'description' => 'Zieldistanz in km'],
                    'duration_min' => ['type' => 'integer', 'description' => 'Zieldauer in Minuten'],
                    'pace_target'  => ['type' => 'string', 'description' => 'Zielpace im Format M:SS'],
                    'zone'         => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'description' => 'Herzfrequenzzone'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'skip_training_sessions',
                'description' => 'Markiere Trainingseinheiten als übersprungen – bei Krankheit, Verletzung, Urlaub oder erzwungener Pause.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string', 'description' => 'Startdatum YYYY-MM-DD'],
                    'date_to'   => ['type' => 'string', 'description' => 'Enddatum YYYY-MM-DD'],
                    'reason'    => ['type' => 'string', 'description' => 'Grund (z.B. "Grippe", "Knieprobleme", "Urlaub")'],
                ], 'required' => ['date_from', 'date_to']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'update_event_target',
                'description' => 'Aktualisiere die Zielzeit für ein Event, wenn der Athlet sie anpassen möchte.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'event_id'       => ['type' => 'integer', 'description' => 'Event-ID aus den Athleten-Daten'],
                    'target_hours'   => ['type' => 'integer', 'description' => 'Stunden der Zielzeit'],
                    'target_minutes' => ['type' => 'integer', 'description' => 'Minuten (0–59)'],
                ], 'required' => ['event_id', 'target_hours', 'target_minutes']],
            ]],
        ];
    }

    /** Execute a single coach tool call. Returns ['message', 'action']. */
    private function executeCoachTool(\App\Models\User $user, string $toolName, array $args): array
    {
        switch ($toolName) {
            case 'remember_user_fact': {
                $fact = trim($args['fact'] ?? '');
                if (!$fact) return ['message' => 'Keine Angabe.', 'action' => null];
                $profile = $user->runnerProfile ?? \App\Models\RunnerProfile::firstOrCreate(['user_id' => $user->id]);
                $existing = $profile->coach_notes ?? '';
                $profile->coach_notes = trim($existing . "\n- " . $fact);
                $profile->save();
                return ['message' => 'Gespeichert.', 'action' => ['type' => 'memory', 'label' => 'Gemerkt: ' . mb_substr($fact, 0, 80)]];
            }

            case 'modify_today_session': {
                $today   = now()->toDateString();
                $session = \App\Models\TrainingSession::where('user_id', $user->id)
                    ->whereDate('planned_date', $today)->where('status', '!=', 'skipped')->orderBy('sort_order')->first();
                if (!$session) {
                    $session = new \App\Models\TrainingSession();
                    $session->user_id = $user->id;
                    $session->planned_date = $today;
                    $session->status = 'planned';
                    $session->sort_order = 1;
                }
                foreach (['type','title','description','distance_km','duration_min','pace_target','zone'] as $f) {
                    if (array_key_exists($f, $args)) $session->{$f} = $args[$f];
                }
                // Clear cached steps and nutrition tips so they get regenerated with the new parameters
                $session->steps         = null;
                $session->nutrition_tips = null;
                $session->save();

                // Regenerate the dashboard recommendation + daily message with the new context
                $this->invalidateCoachCaches($user);
                $label = $session->title ?? ($args['type'] ?? 'Training');
                return ['message' => 'Einheit aktualisiert.', 'action' => ['type' => 'session_modified', 'label' => 'Training angepasst: ' . $label, 'reload' => true]];
            }

            case 'skip_training_sessions': {
                $from   = $args['date_from'] ?? now()->toDateString();
                $to     = $args['date_to']   ?? $from;
                $reason = $args['reason']    ?? '';
                $count  = \App\Models\TrainingSession::where('user_id', $user->id)
                    ->whereBetween('planned_date', [$from, $to])->where('status', 'planned')->update(['status' => 'skipped']);

                // A skipped session changes today's context → refresh recommendation + daily message
                if ($count > 0) {
                    $this->invalidateCoachCaches($user);
                }

                $detail = $reason ? " ($reason)" : '';
                return ['message' => "{$count} Einheiten übersprungen.", 'action' => ['type' => 'sessions_skipped', 'label' => "{$count} " . ($count === 1 ? 'Einheit' : 'Einheiten') . " übersprungen{$detail}", 'reload' => true]];
            }

            case 'update_event_target': {
                $event = \App\Models\Event::where('id', $args['event_id'] ?? 0)->where('user_id', $user->id)->first();
                if (!$event) return ['message' => 'Event nicht gefunden.', 'action' => null];
                $event->target_time_hours   = max(0, (int)($args['target_hours'] ?? 0));
                $event->target_time_minutes = max(0, min(59, (int)($args['target_minutes'] ?? 0)));
                $event->save();
                $this->invalidateCoachCaches($user);
                $formatted = $event->target_time_formatted ?? 'aktualisiert';
                return ['message' => "Zielzeit gespeichert: {$formatted}.", 'action' => ['type' => 'event_updated', 'label' => "Zielzeit {$event->name}: {$formatted}", 'reload' => true]];
            }

            default:
                return ['message' => 'Unbekanntes Tool.', 'action' => null];
        }
    }

    /**
     * Invalidate cached coach outputs (dashboard recommendation + daily message)
     * so they regenerate with fresh context after a plan/session change.
     */
    private function invalidateCoachCaches(\App\Models\User $user): void
    {
        $profile = $user->runnerProfile ?? \App\Models\RunnerProfile::firstOrCreate(['user_id' => $user->id]);
        $profile->update([
            'today_recommendation' => null,
            'recommendation_date'  => null,
            'daily_message'        => null,
            'daily_message_date'   => null,
        ]);
    }

    /**
     * Coach chat with tool use: memory, session modification, skip sessions, event target.
     * Returns ['reply' => string|null, 'actions' => array].
     */
    public function chatWithCoachTools(\App\Models\User $user, array $history, string $newMessage): array
    {
        $today   = now()->toDateString();
        $profile = $user->runnerProfile;

        $mpsToMSS = function (float $mps): string {
            if ($mps <= 0) return '—';
            $s = 1000 / $mps;
            return sprintf('%d:%02d', (int)($s / 60), (int)$s % 60);
        };
        $floatMinToMSS = function (?float $min): string {
            if (!$min) return '—';
            $m = (int)$min;
            return sprintf('%d:%02d', $m, (int)round(($min - $m) * 60));
        };

        // Profile
        $profileLines = [];
        if ($profile) {
            if ($profile->threshold_speed)      $profileLines[] = 'Schwellenpace: ' . $floatMinToMSS($profile->threshold_speed) . ' min/km';
            if ($profile->threshold_heart_rate) $profileLines[] = 'LTHR: ' . $profile->threshold_heart_rate . ' bpm';
            if ($profile->max_heart_rate)       $profileLines[] = 'Max HR: ' . $profile->max_heart_rate . ' bpm';
            if (!empty($profile->pace_zones)) {
                $zStr = collect($profile->pace_zones)->map(fn ($r, $z) => "Z{$z}: " . $floatMinToMSS($r['min'] ?? null) . '–' . $floatMinToMSS($r['max'] ?? null))->implode(' | ');
                if ($zStr) $profileLines[] = 'Pace-Zonen: ' . $zStr;
            }
        }
        $firstRun = $user->activities()->where('type', 'Run')->oldest('start_date')->first();
        if ($firstRun) {
            $months = (int)$firstRun->start_date->diffInMonths(now());
            $profileLines[] = 'Läuft seit: ca. ' . ($months < 24 ? "{$months} Monate" : round($months / 12, 1) . ' Jahre');
        }

        // Weekly km
        $weeklyLines = [];
        for ($w = 0; $w < 4; $w++) {
            $wStart = now()->startOfWeek()->subWeeks($w);
            $km     = round($user->activities()->where('type','Run')->whereBetween('start_date', [$wStart, (clone $wStart)->addWeek()])->sum('distance') / 1000, 1);
            $weeklyLines[] = match ($w) { 0 => 'Aktuelle Woche', 1 => 'Letzte Woche', default => "Vor {$w} Wochen" } . ": {$km} km";
        }

        // Training distribution
        $typeMap = ['easy_run'=>'Lockere Läufe','tempo_run'=>'Tempoläufe','interval'=>'Intervalle','long_run'=>'Lange Läufe','progressive_run'=>'Progressive Läufe','test_run'=>'Testläufe','race_prep'=>'Rennvorbereitung'];
        $byType  = \App\Models\TrainingSession::where('user_id',$user->id)->where('status','completed')->where('planned_date','>=',now()->subDays(30)->toDateString())->selectRaw('type, count(*) as cnt')->groupBy('type')->pluck('cnt','type')->toArray();
        $distLines = array_map(fn($t,$c) => ($typeMap[$t]??$t).': '.$c.'×', array_keys($byType), $byType);

        // Recent runs
        $recentRuns = $user->activities()->where('type','Run')->orderByDesc('start_date')->limit(10)->get()->map(function($a) use ($mpsToMSS) {
            $km    = number_format(($a->distance??0)/1000,1);
            $pace  = $a->average_speed>0 ? $mpsToMSS((float)$a->average_speed).' min/km' : '—';
            $dur   = $a->moving_time ? (int)round($a->moving_time/60).' min' : '';
            $hr    = $a->average_heartrate ? (int)$a->average_heartrate.' bpm' : '';
            $hrMax = $a->max_heartrate    ? '/ '.(int)$a->max_heartrate.' max' : '';
            return '- '.$a->start_date->format('d.m.').' "'.$a->name.'": '.implode(' | ', array_filter([$km.' km',$pace,$dur,trim($hr.' '.$hrMax)]));
        })->implode("\n");

        // Today's session
        $todaySession = \App\Models\TrainingSession::where('user_id',$user->id)->whereDate('planned_date',$today)->where('status','!=','skipped')->orderBy('sort_order')->first();

        // Upcoming
        $upcoming = \App\Models\TrainingSession::where('user_id',$user->id)->whereDate('planned_date','>',$today)->whereDate('planned_date','<=',now()->addDays(7)->toDateString())->where('type','!=','rest')->orderBy('planned_date')->limit(5)->get();

        // Events (include ID for tool use)
        $events = $user->events()->where('event_date','>=',$today)->orderBy('event_date')->limit(4)->get();

        // Wellbeing
        $wellbeing = $user->wellbeingEntries()->whereDate('date',$today)->first();

        // Build context
        $ctx = [];
        $coachNotes = $profile?->coach_notes ? trim($profile->coach_notes) : null;
        if ($coachNotes) $ctx[] = "WAS ICH ÜBER DICH WEISS:\n{$coachNotes}";
        if ($profileLines) $ctx[] = "ATHLETENPROFIL:\n".implode("\n",$profileLines);
        if ($weeklyLines)  $ctx[] = "WOCHENKILOMETER:\n".implode("\n",$weeklyLines);
        if ($distLines)    $ctx[] = "TRAININGSVERTEILUNG (30 Tage):\n".implode(', ',$distLines);
        if ($recentRuns)   $ctx[] = "LETZTE LÄUFE:\n{$recentRuns}";

        if ($todaySession) {
            if ($todaySession->type === 'rest') {
                $ctx[] = "HEUTIGES TRAINING (ID:{$todaySession->id})".(($todaySession->status==='completed')?' (erledigt)':'').": Ruhetag";
            } else {
                $d = "Typ:{$todaySession->type}, Titel:\"{$todaySession->title}\"";
                if ($todaySession->distance_km) $d .= ", {$todaySession->distance_km}km";
                if ($todaySession->duration_min) $d .= ", {$todaySession->duration_min}min";
                if ($todaySession->pace_target && $todaySession->pace_target!=='null') $d .= ", Pace:{$todaySession->pace_target}";
                if ($todaySession->zone) $d .= ", Zone{$todaySession->zone}";
                $s = $todaySession->status==='completed' ? ' (absolviert)' : '';
                $ctx[] = "HEUTIGES TRAINING (ID:{$todaySession->id}){$s}:\n  {$d}".($todaySession->description ? "\n  Beschreibung: {$todaySession->description}" : '');
            }
        } else {
            $ctx[] = "HEUTIGES TRAINING: Kein Training geplant.";
        }

        if ($upcoming->isNotEmpty()) {
            $ctx[] = "NÄCHSTE 7 TAGE:\n".$upcoming->map(fn($s)=>'- '.$s->planned_date->format('d.m.').' '.$s->title.' ('.$s->type.($s->distance_km?", {$s->distance_km}km":'').')')->implode("\n");
        }
        if ($events->isNotEmpty()) {
            $ctx[] = "EVENTS:\n".$events->map(function($e){
                $days=(int)now()->startOfDay()->diffInDays($e->event_date->copy()->startOfDay(),false);
                $target=$e->target_time_formatted ? ", Ziel:{$e->target_time_formatted}" : '';
                return "- [ID:{$e->id}] {$e->name} ({$e->distance_label}) {$e->event_date->format('d.m.Y')} (in {$days}d) [".match($e->priority){'A'=>'★A','B'=>'B',default=>'C'}."{$target}]";
            })->implode("\n");
        }
        if ($wellbeing) $ctx[] = "WELLBEING: Energie {$wellbeing->energy_level}/10, Schlaf {$wellbeing->sleep_quality}/10, Stimmung {$wellbeing->mood}/10";

        $contextBlock = "\n\n=== ATHLETEN-DATEN ({$today}) ===\n".implode("\n\n",$ctx)."\n=== ENDE ===";
        $coachName = $user->coach?->name ?? 'Coach';

        $systemPrompt = $this->buildSystemPrompt(
            "Du bist {$coachName}, der persönliche Lauf-Coach von {$user->name}. ".
            "Du kennst alle Trainingsdaten und antwortest wie ein echter Coach der seinen Athleten kennt. ".
            "Du hast Werkzeuge um: Infos dauerhaft zu merken (remember_user_fact), die heutige Einheit anzupassen (modify_today_session), Einheiten bei Krankheit/Urlaub zu überspringen (skip_training_sessions), Zielzeiten zu aktualisieren (update_event_target). ".
            "Nutze Tools proaktiv: Athlet sagt er ist krank → überspringe Einheiten. 'Zu leicht' → ändere Einheit. Präferenz geäußert → merke sie dir. ".
            "Antworte auf Deutsch, sprich mit 'du'. Passe Länge der Antwort der Frage an. Nutze Markdown für strukturierte Antworten. ".
            "Stütze dich IMMER auf echte Zahlen aus den Daten.".
            $contextBlock
        );

        $messages = [['role'=>'system','content'=>$systemPrompt]];
        foreach ($history as $msg) $messages[] = ['role'=>$msg['role'],'content'=>$msg['content']];
        $messages[] = ['role'=>'user','content'=>$newMessage];

        $tools        = $this->coachTools();
        $actionsTaken = [];

        for ($i = 0; $i < 3; $i++) {
            $startMs  = (int)round(microtime(true)*1000);
            $response = Http::withHeaders(['Authorization'=>'Bearer '.$this->apiKey,'Content-Type'=>'application/json'])
                ->timeout(90)
                ->post($this->baseUrl.'/chat/completions', [
                    'model'                 => $this->model,
                    'messages'              => $messages,
                    'max_completion_tokens' => 2500,
                    'tools'                 => $tools,
                    'tool_choice'           => 'auto',
                ]);

            $durationMs = (int)round(microtime(true)*1000) - $startMs;
            $body       = $response->json() ?? [];
            $usage      = $body['usage'] ?? [];
            $choice     = $body['choices'][0] ?? null;

            AiLog::create([
                'user_id'           => $this->userId,
                'call_type'         => 'coach_chat',
                'model'             => $this->model,
                'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens'      => $usage['total_tokens'] ?? 0,
                'cost_eur'          => AiLog::calculateCost($this->model, $usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0),
                'duration_ms'       => $durationMs,
                'status'            => $response->failed() ? 'error' : 'success',
                'error_message'     => $response->failed() ? ('HTTP '.$response->status().': '.mb_substr($response->body(),0,300)) : null,
                'full_response'     => data_get($body,'choices.0.message.content',''),
            ]);

            if ($response->failed() || !$choice) break;

            $assistantMsg = $choice['message'];
            $finishReason = $choice['finish_reason'] ?? 'stop';

            if ($finishReason !== 'tool_calls' || empty($assistantMsg['tool_calls'])) {
                return ['reply' => $assistantMsg['content'] ?? '', 'actions' => array_values(array_filter($actionsTaken))];
            }

            $messages[] = $assistantMsg;
            foreach ($assistantMsg['tool_calls'] as $toolCall) {
                $result = $this->executeCoachTool($user, $toolCall['function']['name'], json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? []);
                if ($result['action']) $actionsTaken[] = $result['action'];
                $messages[] = ['role'=>'tool','tool_call_id'=>$toolCall['id'],'content'=>$result['message']];
            }
        }

        return ['reply' => null, 'actions' => array_values(array_filter($actionsTaken))];
    }

    private function formatSeconds(int $seconds): string
    {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    /**
     * Generate a short AI coaching recommendation based on the race prediction.
     *
     * @param array $predictionData  Output from PredictFinishTimeService::predict()
     * @param array $eventData       ['name', 'race_distance', 'target_time_formatted', 'days_until']
     * @param array $recentSessions  Last 5 completed/planned sessions [{type, distance_km, status}]
     * @return string|null
     */
    public function generateRacePredictionText(
        array  $predictionData,
        array  $eventData,
        array  $recentSessions = [],
    ): ?string {
        $predicted = $predictionData['predicted_finish_time'];
        $pace      = $predictionData['predicted_pace'];
        $trend     = $predictionData['prediction_trend'];
        $deltaSec  = $predictionData['prediction_target_delta_sec'] ?? null;
        $runCount  = $predictionData['prediction_run_count'];

        $trendText = match ($trend) {
            'improving' => 'Der Athlet verbessert sich – aktuelle Läufe sind schneller als ältere.',
            'declining' => 'Die Leistung sinkt leicht – aktuelle Läufe sind langsamer als ältere.',
            default     => 'Die Leistung ist stabil.',
        };

        $deltaText = '';
        if ($deltaSec !== null) {
            $absSec    = abs($deltaSec);
            $h         = (int)($absSec / 3600);
            $m         = (int)(($absSec % 3600) / 60);
            $s         = $absSec % 60;
            $formatted = $h > 0
                ? sprintf('%d:%02d:%02d', $h, $m, $s)
                : sprintf('%d:%02d', $m, $s);
            $deltaText = $deltaSec >= 0
                ? "Die Prognose liegt {$formatted} unter der Zielzeit – der Athlet ist auf Kurs."
                : "Der Athlet liegt {$formatted} hinter der Zielzeit zurück.";
        }

        $sessionsText = '';
        if (! empty($recentSessions)) {
            $lines = array_map(fn ($s) => "- {$s['type']} ({$s['distance_km']} km, Status: {$s['status']})", $recentSessions);
            $sessionsText = "\nLetzte Trainingseinheiten:\n" . implode("\n", $lines);
        }

        $prompt = <<<PROMPT
Du bist ein persönlicher Lauf-Coach. Schreibe eine kurze, motivierende Empfehlung (2–3 Sätze) auf Deutsch.

Event: {$eventData['name']} ({$eventData['race_distance']}) in {$eventData['days_until']} Tagen
Zielzeit: {$eventData['target_time_formatted']}
Prognostizierte Finishzeit: {$predicted} (Pace: {$pace}/km)
Basiert auf: {$runCount} Läufen der letzten 90 Tage
Trend: {$trendText}
{$deltaText}{$sessionsText}

Schreibe die Empfehlung direkt (kein "Hallo", keine Einleitung). Maximal 3 Sätze. Sei konkret: Nenne welche Trainingsart hilft und warum. Keine Emojis.
PROMPT;

        return $this->callOpenAI('race_prediction_text', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Du bist ein erfahrener Lauf-Coach. Antworte immer auf Deutsch. Sei direkt, sachlich und motivierend. Keine Emojis. Max. 3 Sätze.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.6, 800, 45, $this->modelMini);
    }

    /**
     * Race-day pacing & fueling strategy text.
     *
     * @param array       $eventData ['name', 'race_distance', 'target_time_formatted', 'days_until']
     * @param string      $goalPace  e.g. "5:00"
     * @param array|null  $weather   Output of WeatherService::forUser(), optional
     */
    public function generateRaceStrategy(array $eventData, string $goalPace, ?array $weather = null): ?string
    {
        $weatherText = '';
        if ($weather && isset($weather['temp_c'])) {
            $weatherText = "\nWetter am Ort: {$weather['description']}, {$weather['temp_c']}°C"
                . (($weather['precip_prob'] ?? null) !== null ? ", Regen {$weather['precip_prob']}%" : '')
                . (($weather['wind_kmh'] ?? null) !== null ? ", Wind {$weather['wind_kmh']} km/h" : '')
                . '.';
        }

        $prompt = <<<PROMPT
Du bist ein persönlicher Lauf-Coach. Schreibe eine kompakte Renntag-Strategie (3–5 Sätze) auf Deutsch.

Wettkampf: {$eventData['name']} ({$eventData['race_distance']}) in {$eventData['days_until']} Tagen
Zielzeit: {$eventData['target_time_formatted']}
Zielpace: {$goalPace}/km{$weatherText}

Gehe konkret ein auf: (1) Pacing — gleichmäßig oder leicht negativer Split, erste Kilometer bewusst kontrolliert; (2) Verpflegung passend zur Distanz (bei Halbmarathon/Marathon Gels/Kohlenhydrate + Trinken, bei 5–10 km kaum nötig); (3) ein mentaler Cue für die harte Phase. Falls Wetter genannt und relevant ist (Hitze/Kälte/Regen/Wind), gib einen kurzen Hinweis. Direkt, ohne Einleitung, keine Emojis.
PROMPT;

        return $this->callOpenAI('race_strategy', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Du bist ein erfahrener Lauf-Coach. Antworte auf Deutsch, direkt und konkret. Keine Emojis. Max. 5 Sätze.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.6, 900, 45, $this->modelMini);
    }

    /**
     * Post-race analysis text comparing target vs. actual and pacing.
     *
     * @param array       $eventData  ['name', 'race_distance']
     * @param string|null $targetTime formatted target time (or null)
     * @param array       $actual     ['time', 'pace', 'distance_km', 'splits_text'?]
     */
    public function generateRaceAnalysis(array $eventData, ?string $targetTime, array $actual): ?string
    {
        $targetText = $targetTime ? "Zielzeit: {$targetTime}" : 'Keine Zielzeit gesetzt';
        $splitsText = ! empty($actual['splits_text']) ? "\nSplits (km-weise):\n{$actual['splits_text']}" : '';

        $prompt = <<<PROMPT
Du bist ein persönlicher Lauf-Coach. Schreibe eine Renn-Auswertung (4–6 Sätze) auf Deutsch.

Wettkampf: {$eventData['name']} ({$eventData['race_distance']})
{$targetText}
Tatsächliche Zeit: {$actual['time']} (Pace {$actual['pace']}/km, {$actual['distance_km']} km){$splitsText}

Werte konkret aus: (1) Ziel vs. Ist — Ziel erreicht? Wie groß die Abweichung; (2) Pacing-Konsistenz — gleichmäßig gelaufen oder am Ende eingebrochen (nutze die Splits, falls vorhanden); (3) was gut lief; (4) 1–2 konkrete Learnings fürs nächste Rennen. Direkt, ehrlich und motivierend. Keine Emojis.
PROMPT;

        return $this->callOpenAI('race_analysis', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Du bist ein erfahrener Lauf-Coach. Antworte auf Deutsch, ehrlich und konstruktiv. Keine Emojis. 4–6 Sätze.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.6, 1000, 45, $this->modelMini);
    }

    /**
     * Personal "Wrapped" retrospective text for a period.
     *
     * @param array  $stats       Output of WrappedService::generate()
     * @param string $periodLabel e.g. "2026" or "Juni 2026"
     */
    public function generateWrappedReview(array $stats, string $periodLabel): ?string
    {
        $t = $stats['totals'] ?? [];
        $lines = [
            "Zeitraum: {$periodLabel}",
            'Läufe: ' . ($t['runs'] ?? 0) . ', Distanz: ' . ($t['km'] ?? 0) . ' km, Zeit: ' . ($t['hours'] ?? 0)
                . ' h, Höhenmeter: ' . ($t['elevation'] ?? 0) . ' m, aktive Tage: ' . ($t['active_days'] ?? 0),
        ];
        if (! empty($stats['longest_run']))      $lines[] = "Längster Lauf: {$stats['longest_run']['km']} km";
        if (! empty($stats['fastest_run']))      $lines[] = "Schnellster Lauf: Pace {$stats['fastest_run']['pace']}/km über {$stats['fastest_run']['km']} km";
        if (! empty($stats['favorite_weekday'])) $lines[] = "Lieblings-Wochentag: {$stats['favorite_weekday']['label']}";
        if (! empty($stats['longest_streak']))   $lines[] = "Längste Serie: {$stats['longest_streak']} Tage in Folge";
        if (! empty($stats['prs']['count']))     $lines[] = "Neue persönliche Rekorde: {$stats['prs']['count']}";
        if (! empty($stats['vs_previous'])) {
            $d = $stats['vs_previous']['delta_pct'];
            $lines[] = "Vergleich zu {$stats['vs_previous']['prev_label']}: " . ($d >= 0 ? "+{$d}" : $d) . '% km';
        }
        $data = implode("\n", $lines);

        $prompt = <<<PROMPT
Daten des Athleten für den Rückblick:
{$data}

Schreibe einen kurzen, persönlichen und motivierenden Rückblick (3–5 Sätze) auf Deutsch. Sprich den Athleten direkt an (du). Hebe 1–2 Highlights hervor und schließe ermutigend ab. Keine Aufzählung, kein Markdown, nur Fließtext.
PROMPT;

        return $this->callOpenAI('wrapped_review', [
            ['role' => 'system', 'content' => $this->buildSystemPrompt('Du bist ein motivierender Lauf-Coach. Antworte auf Deutsch, warm und persönlich. Nur Fließtext.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.8, 900, 45, $this->modelMini);
    }

    /**
     * Generate a German plain-language summary of a GitHub push for the admin wiki changelog.
     */
    public function generateChangelogSummary(array $commits, array $filesChanged): ?string
    {
        $commitLines = implode("\n", array_map(
            fn ($c) => "- [{$c['id']}] {$c['message']} (von {$c['author']})",
            array_slice($commits, 0, 10)
        ));
        $fileLines = implode(', ', array_slice($filesChanged, 0, 15));
        if (count($filesChanged) > 15) {
            $fileLines .= ' … (' . count($filesChanged) . ' Dateien gesamt)';
        }

        $prompt = <<<PROMPT
Du bist der technische Dokumentations-Bot für Zone3, eine Laravel/Vue.js Lauf-Trainingsplattform.

Fasse diese GitHub-Push-Zusammenfassung auf Deutsch zusammen:

Commits:
{$commitLines}

Geänderte Dateien: {$fileLines}

Schreibe eine kompakte, klare Zusammenfassung (3-5 Sätze) die erklärt:
1. Was wurde geändert (technisch, aber verständlich)
2. Was bedeutet das für den Nutzer / die Plattform
3. Welche Bereiche des Systems sind betroffen

Schreibe auf Deutsch, direkt und ohne Floskeln. Keine Überschrift, nur der Text.
PROMPT;

        return $this->callOpenAI('changelog_summary', [
            ['role' => 'system', 'content' => 'Du bist ein technischer Dokumentations-Assistent. Antworte nur mit der reinen Zusammenfassung auf Deutsch.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.4, 1200, 30, $this->modelMini);
    }
}


