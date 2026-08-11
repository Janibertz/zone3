<?php

namespace App\Services\AI;

use App\Models\AiLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Der Zugang zu OpenAI: ein Aufruf, ein Protokolleintrag.
 *
 * Vorher steckte dieser Transport in derselben Klasse wie zweiundzwanzig
 * Prompt-Bauer — 2786 Zeilen, in denen die Fachlichkeit und die Technik
 * nicht zu trennen waren. Hier bleibt nur noch, was jeder Aufruf braucht:
 * Modellwahl, Zeitlimit, Kostenprotokoll und Fehlerbehandlung.
 *
 * Der Zustand (Nutzer, Coach-Persoenlichkeit) ist bewusst veraenderlich und
 * gilt fuer die Dauer eines Requests beziehungsweise eines Jobs.
 */
class OpenAIClient
{
    protected string $apiKey;
    protected string $model;
    protected string $modelMini;
    protected string $baseUrl = 'https://api.openai.com/v1';
    protected ?string $coachPersonality = null;
    protected ?int $userId = null;

    public function __construct()
    {
        $this->apiKey    = config('services.openai.api_key');
        $this->model     = config('services.openai.model',      'gpt-5.5-2026-04-23');
        $this->modelMini = config('services.openai.model_mini', 'gpt-5.4-mini');
    }

    /** Das grosse Modell — fuer Plaene, Schwellenpace und Coach-Chat. */
    public function main(): string
    {
        return $this->model;
    }

    /** Das kleine Modell — fuer alles Kurze. */
    public function mini(): string
    {
        return $this->modelMini;
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

    /**
     * Shared coaching stance injected into every coaching-text prompt (chat, daily
     * message, weekly review, race messages, plan generation). Makes the coach
     * ambitious and honest instead of merely reassuring: it takes the athlete's
     * goal seriously, warns clearly when the goal is at risk, and pushes the
     * athlete to aim higher when the data shows they are ahead — while staying
     * supportive and fair, never demeaning.
     */
    protected const COACHING_PHILOSOPHY =
        'Coaching-Grundhaltung: Sei ehrgeizig, ehrlich und anspornend. Nimm das Ziel des Athleten ernst und arbeite zielgerichtet darauf hin. '
        . 'Zeigen Daten/Fortschritt, dass das Ziel beim aktuellen Training gefährdet ist, benenne das klar und respektvoll und sag konkret, was sich ändern muss (mehr Umfang, Tempoeinheiten nicht auslassen o.Ä.). '
        . 'Ist der Athlet besser als sein Ziel, ermutige ihn ausdrücklich, sich ein ehrgeizigeres Ziel zu setzen. '
        . 'Motiviere, fordere und spornt an – bleib dabei immer unterstützend, fair und respektvoll, niemals abwertend oder entmutigend.';

    public function systemPrompt(string $base): string
    {
        $philosophy = self::COACHING_PHILOSOPHY;
        if ($this->coachPersonality) {
            return $this->coachPersonality . ' ' . $philosophy . ' ' . $base;
        }
        return $philosophy . ' ' . $base;
    }

    /**
     * Central OpenAI HTTP call with automatic AiLog entry.
     * Returns content string from choices[0].message.content, or null on failure.
     */
    public function chat(
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
     * Ein Aufruf mit Werkzeugen.
     *
     * Anders als {@see chat()} liefert er die ganze Antwort zurueck: der
     * Coach-Chat muss zwischen einer Textantwort und einem Werkzeugaufruf
     * unterscheiden und die Schleife selbst weiterdrehen. Vorher baute er
     * sich diesen Aufruf samt Schluessel und Protokolleintrag selbst
     * zusammen — beim Zerlegen waere ihm der Schluessel abhanden gekommen.
     *
     * @return array|null Der dekodierte Antwortkoerper, null bei Fehler.
     */
    public function chatWithTools(
        string $callType,
        array  $messages,
        array  $tools,
        int    $maxTokens = 2500,
        int    $timeout   = 90,
    ): ?array {
        $startMs = (int) round(microtime(true) * 1000);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ])->timeout($timeout)->post($this->baseUrl . '/chat/completions', [
            'model'                 => $this->model,
            'messages'              => $messages,
            'max_completion_tokens' => $maxTokens,
            'tools'                 => $tools,
            'tool_choice'           => 'auto',
        ]);

        $durationMs = (int) round(microtime(true) * 1000) - $startMs;
        $body       = $response->json() ?? [];
        $usage      = $body['usage'] ?? [];

        AiLog::create([
            'user_id'           => $this->userId,
            'call_type'         => $callType,
            'model'             => $this->model,
            'prompt_tokens'     => $usage['prompt_tokens']     ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens'      => $usage['total_tokens']      ?? 0,
            'cost_eur'          => AiLog::calculateCost($this->model, $usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0),
            'duration_ms'       => $durationMs,
            'status'            => $response->failed() ? 'error' : 'success',
            'error_message'     => $response->failed()
                ? 'HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300)
                : null,
            'full_response'     => data_get($body, 'choices.0.message.content', ''),
        ]);

        if ($response->failed()) {
            Log::error("OpenAI {$callType} Error", ['status' => $response->status()]);

            return null;
        }

        return $body;
    }

    /**
     * Ein JSON-Objekt aus der Antwort ziehen.
     *
     * Reasoning-Modelle stellen der Antwort gern einen Satz voran, obwohl der
     * Prompt reines JSON verlangt. Diese beiden Helfer standen deshalb als
     * `preg_match('/\{.*\}/s', ...)` zwanzigmal im alten Dienst.
     */
    public function jsonObject(?string $text): ?array
    {
        if (! $text || ! preg_match('/\{.*\}/s', $text, $m)) {
            return null;
        }

        $data = json_decode($m[0], true);

        return json_last_error() === JSON_ERROR_NONE && is_array($data) ? $data : null;
    }

    /** Dasselbe fuer ein JSON-Array. */
    public function jsonArray(?string $text): ?array
    {
        if (! $text || ! preg_match('/\[.*\]/s', $text, $m)) {
            return null;
        }

        $data = json_decode($m[0], true);

        return json_last_error() === JSON_ERROR_NONE && is_array($data) ? $data : null;
    }
}
