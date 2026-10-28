<?php

namespace App\Services\AI;

use App\Models\AiLog;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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
 *
 * Seit dem 28.10.2026 kostet jeder Aufruf echtes Geld (das Teilen der Daten
 * mit OpenAI ist aus, und damit das Freikontingent). Deshalb stehen hier
 * auch die Bremsen: Flex fuer alles im Hintergrund, ein Tagesbudget pro
 * Athlet und fuer alle zusammen, und eine Notbremse, wenn OpenAI meldet,
 * dass Guthaben oder Ausgabenlimit erschoepft sind.
 */
class OpenAIClient
{
    /**
     * Aufrufarten, die das Tagesbudget eines Athleten nicht sperrt.
     *
     * Der Plan ist der Kern der App und laeuft fast immer ohne Zutun des
     * Athleten (Sonntag 19:00, Lueckenschluss um 05:00). Ein Athlet, der
     * tagsueber viel mit dem Coach schreibt, soll dadurch nicht ohne Woche
     * dastehen. Das Tagesbudget fuer alle gilt trotzdem.
     */
    public const BUDGET_EXEMPT = ['event_plan'];

    /** Cache-Schluessel der Notbremse. */
    public const BLOCK_KEY = 'openai:blocked';

    /** Wie lange die Notbremse nach einem Quota-Fehler haelt. */
    private const BLOCK_MINUTES = 60;

    /**
     * Flex antwortet langsamer als Standard. Das Zeitlimit wird deshalb
     * verdoppelt; laeuft es trotzdem ab, folgt ein Versuch auf Standard.
     * Die Jobs, die Flex benutzen, haben ihr Zeitlimit darauf ausgelegt.
     */
    public const FLEX_TIMEOUT_FACTOR = 2;

    protected string $apiKey;
    protected string $model;
    protected string $modelMid;
    protected string $modelMini;
    protected string $baseUrl = 'https://api.openai.com/v1';
    protected ?string $coachPersonality = null;
    protected ?int $userId = null;

    public function __construct()
    {
        // Ohne die Umgebungsvariablen liefert config() null. Die Eigenschaften
        // sind als string deklariert — die Klasse liess sich dann nicht einmal
        // erzeugen, und der Container warf einen TypeError, lange bevor
        // irgendjemand einen Aufruf machen wollte. Ein fehlender Schluessel
        // soll beim Aufruf scheitern, nicht beim Hochfahren.
        //
        // Die Vorgaben stehen in config/services.php — hier nur ein Rueckfall
        // auf dieselben Namen, nicht auf eine zweite, veraltende Liste.
        $this->apiKey    = config('services.openai.api_key')    ?? '';
        $this->model     = config('services.openai.model')      ?: 'gpt-5.6-sol';
        $this->modelMid  = config('services.openai.model_mid')  ?: 'gpt-5.6-terra';
        $this->modelMini = config('services.openai.model_mini') ?: 'gpt-5.6-luna';
    }

    /** Das grosse Modell — nur noch fuer den Plan. */
    public function main(): string
    {
        return $this->model;
    }

    /**
     * Das mittlere Modell — Coach-Chat und Schwellenpace.
     *
     * Der Chat laeuft mit `reasoning_effort: none` (siehe chatWithTools()),
     * also ohne das, wofuer das grosse Modell den Aufpreis nimmt. Die
     * Schwellenpace wird plausibilisiert und ist nur eine von drei Quellen
     * des VDOT. Fuer beide reicht die Mitte zum halben Preis.
     */
    public function mid(): string
    {
        return $this->modelMid;
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

    /**
     * Darf der Athlet heute noch mit dem Coach schreiben?
     *
     * Zwei Grenzen: die Anzahl (`ai_daily_limit`, im Admin pro Athlet
     * einstellbar) und das Geld ({@see blockReason()}). Die Anzahl allein
     * sagte nichts darueber, was ein Gespraech mit drei Werkzeugrunden kostet.
     */
    public function isRateLimited(): bool
    {
        if ($this->blockReason('coach_chat') !== null) return true;

        if (!$this->userId) return false;
        $user  = User::find($this->userId);
        if (!$user) return false;
        $limit = $user->ai_daily_limit ?? 20;
        return AiLog::todayCountForUser($this->userId) >= $limit;
    }

    /**
     * Warum ein Aufruf jetzt nicht stattfinden darf — oder null.
     *
     * Drei Bremsen, die teuerste zuerst:
     *
     *  1. **Notbremse.** OpenAI hat `insufficient_quota` oder ein
     *     Ausgabenlimit gemeldet. Jeder weitere Aufruf scheitert ohnehin;
     *     ohne Sperre rennt jeder Job und jeder Tagesform-Eintrag einzeln
     *     gegen dieselbe Wand.
     *  2. **Tagesbudget fuer alle** (`budget.daily_eur`). Faengt eine
     *     Schleife ab, die das Monatslimit der Plattform sonst erst am
     *     Monatsende stoppt.
     *  3. **Tagesbudget pro Athlet** (`budget.user_daily_eur`), ausser fuer
     *     die Aufrufarten in {@see BUDGET_EXEMPT}.
     *
     * Gezaehlt wird in Euro aus `ai_logs.cost_eur`, nicht in Aufrufen: ein
     * Plan kostet so viel wie hundert Tagessprueche.
     */
    public function blockReason(?string $callType = null): ?string
    {
        if ($reason = Cache::get(self::BLOCK_KEY)) {
            return 'Notbremse: ' . $reason;
        }

        $daily = (float) config('services.openai.budget.daily_eur', 0);
        if ($daily > 0 && AiLog::todayCost() >= $daily) {
            return sprintf('Tagesbudget fuer alle erreicht (%.2f EUR)', $daily);
        }

        if ($this->userId && ! in_array($callType, self::BUDGET_EXEMPT, true)) {
            $perUser = (float) config('services.openai.budget.user_daily_eur', 0);
            if ($perUser > 0 && AiLog::todayCost($this->userId) >= $perUser) {
                return sprintf('Tagesbudget des Athleten erreicht (%.2f EUR)', $perUser);
            }
        }

        return null;
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
     *
     * `$background` heisst: niemand wartet auf die Antwort (Plan, Reviews,
     * Schwellenpace). Dann geht der Aufruf ueber Flex — dasselbe Modell zum
     * halben Preis, nur langsamer. Ein Aufruf, auf den ein Athlet im Browser
     * wartet, bleibt auf Standard.
     */
    public function chat(
        string  $callType,
        array   $messages,
        float   $temperature,
        int     $maxTokens,
        int     $timeout    = 30,
        ?string $model      = null,
        bool    $background = false,
    ): ?string {
        $model = $model ?? $this->model;

        $userContent = '';
        foreach (array_reverse($messages) as $m) {
            if ($m['role'] === 'user') { $userContent = $m['content']; break; }
        }

        if ($blocked = $this->blockReason($callType)) {
            $this->logSkipped($callType, $model, $blocked);

            return null;
        }

        $startMs = (int) round(microtime(true) * 1000);

        try {
            $response = $this->send([
                'model'                 => $model,
                'messages'              => $messages,
                'max_completion_tokens' => $maxTokens,
            ], $timeout, $background);

            $durationMs   = (int) round(microtime(true) * 1000) - $startMs;
            $body         = $response->json() ?? [];
            $content      = data_get($body, 'choices.0.message.content', '');
            $finishReason = data_get($body, 'choices.0.finish_reason');

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
                ...$this->usage($body, $model),
                'duration_ms'      => $durationMs,
                'prompt_preview'   => mb_substr($userContent, 0, 500),
                'response_preview' => mb_substr($content, 0, 500),
                'full_prompt'      => $userContent,
                'full_response'    => $content,
                'status'           => ($failed || $emptyCompletion) ? 'error' : 'success',
                'error_message'    => match (true) {
                    $failed          => 'HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500),
                    $emptyCompletion => "Leere Antwort (finish_reason: " . ($finishReason ?? 'unbekannt') . ", completion_tokens: " . (data_get($body, 'usage.completion_tokens', 0)) . ") – Token-Budget vermutlich durch Reasoning erschöpft, max_completion_tokens erhöhen.",
                    default          => null,
                },
            ]);

            if ($failed) {
                $this->tripIfQuotaExhausted($response);
                Log::error("OpenAI {$callType} Error", ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            if ($emptyCompletion) {
                Log::warning("OpenAI {$callType} empty completion", ['finish_reason' => $finishReason, 'completion_tokens' => data_get($body, 'usage.completion_tokens', 0)]);
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
     * Kein Flex: hier wartet ein Athlet auf seine Antwort.
     *
     * @return array|null Der dekodierte Antwortkoerper, null bei Fehler.
     */
    public function chatWithTools(
        string  $callType,
        array   $messages,
        array   $tools,
        int     $maxTokens = 2500,
        int     $timeout   = 90,
        ?string $model     = null,
    ): ?array {
        $model = $model ?? $this->model;

        if ($blocked = $this->blockReason($callType)) {
            $this->logSkipped($callType, $model, $blocked);

            return null;
        }

        $startMs = (int) round(microtime(true) * 1000);

        $response = $this->send([
            'model'                 => $model,
            'messages'              => $messages,
            'max_completion_tokens' => $maxTokens,
            'tools'                 => $tools,
            'tool_choice'           => 'auto',

            // Ohne diese Zeile antwortet gpt-5.6 mit HTTP 400:
            //
            //   "Function tools with reasoning_effort are not supported for
            //    gpt-5.6-sol in /v1/chat/completions. To use function tools,
            //    use /v1/responses or set reasoning_effort to 'none'."
            //
            // Das Modell bringt ein Standard-Reasoning mit, und das vertraegt
            // sich in Chat Completions nicht mit Function-Tools. Betroffen ist
            // nur dieser Pfad — der Coach-Chat ist die einzige Stelle mit
            // Tools; `chat()` ohne Tools laeuft unveraendert.
            //
            // Der saubere Weg waere /v1/responses, wo Tools UND Reasoning
            // zusammen gehen. Das ist ein anderes Request- und Antwortformat
            // und damit ein eigener Umbau; bis dahin gilt hier, was vorher
            // ohnehin galt: keine interne Denkzeit, dafuer funktionierende
            // Werkzeuge.
            'reasoning_effort'      => 'none',
        ], $timeout, false);

        $durationMs = (int) round(microtime(true) * 1000) - $startMs;
        $body       = $response->json() ?? [];

        $userContent = '';
        foreach (array_reverse($messages) as $m) {
            if (($m['role'] ?? null) === 'user') { $userContent = (string) $m['content']; break; }
        }

        AiLog::create([
            'user_id'           => $this->userId,
            'call_type'         => $callType,
            'model'             => $model,
            ...$this->usage($body, $model),
            'duration_ms'       => $durationMs,
            'status'            => $response->failed() ? 'error' : 'success',
            'error_message'     => $response->failed()
                ? 'HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300)
                : null,
            'prompt_preview'    => mb_substr($userContent, 0, 500),
            'full_prompt'       => $userContent,
            'full_response'     => data_get($body, 'choices.0.message.content', ''),
        ]);

        if ($response->failed()) {
            $this->tripIfQuotaExhausted($response);
            Log::error("OpenAI {$callType} Error", ['status' => $response->status()]);

            return null;
        }

        return $body;
    }

    // ── Transport, Kosten, Bremsen ──────────────────────────────────────

    /**
     * Den Request abschicken — ueber Flex, wenn er im Hintergrund laeuft.
     *
     * Flex kann zweierlei: laenger brauchen, oder mit 429 „Resource
     * Unavailable" ablehnen, wenn OpenAI gerade keine Kapazitaet hat. In
     * beiden Faellen folgt genau ein Versuch auf Standard. Ein 429 wegen
     * leerem Guthaben ist davon ausgenommen — der scheitert auf Standard
     * genauso und loest stattdessen die Notbremse aus.
     */
    private function send(array $payload, int $timeout, bool $background): Response
    {
        if ($background && config('services.openai.flex', true)) {
            try {
                $response = $this->post($payload + ['service_tier' => 'flex'], $timeout * self::FLEX_TIMEOUT_FACTOR);

                if (! ($response->status() === 429 && ! $this->quotaError($response))) {
                    return $response;
                }

                Log::info('OpenAI Flex nicht verfuegbar, Rueckfall auf Standard', [
                    'model' => $payload['model'] ?? null,
                    'body'  => mb_substr($response->body(), 0, 300),
                ]);
            } catch (ConnectionException $e) {
                Log::info('OpenAI Flex Zeitlimit, Rueckfall auf Standard', [
                    'model' => $payload['model'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->post($payload, $timeout);
    }

    private function post(array $payload, int $timeout): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ])->timeout($timeout)->post($this->baseUrl . '/chat/completions', $payload);
    }

    /**
     * Token, Kosten und Tarif aus der Antwort — fuer die `ai_logs`-Zeile.
     *
     * Gecachte Eingabe-Token und Reasoning-Token stehen in eigenen Spalten:
     * erstere kosten ein Zehntel, letztere werden als Ausgabe bezahlt und
     * sind beim Plan der groesste Posten. Ohne sie laesst sich keine
     * Sparmassnahme nachmessen.
     */
    private function usage(array $body, string $model): array
    {
        $usage     = $body['usage'] ?? [];
        $prompt    = (int) ($usage['prompt_tokens']     ?? 0);
        $completion= (int) ($usage['completion_tokens'] ?? 0);
        $cached    = (int) data_get($usage, 'prompt_tokens_details.cached_tokens', 0);
        $reasoning = (int) data_get($usage, 'completion_tokens_details.reasoning_tokens', 0);
        $tier      = $body['service_tier'] ?? null;

        return [
            'prompt_tokens'     => $prompt,
            'completion_tokens' => $completion,
            'total_tokens'      => (int) ($usage['total_tokens'] ?? ($prompt + $completion)),
            'cached_tokens'     => $cached,
            'reasoning_tokens'  => $reasoning,
            'service_tier'      => is_string($tier) ? $tier : null,
            'cost_eur'          => AiLog::calculateCost($model, $prompt, $completion, $cached, $tier === 'flex'),
        ];
    }

    /** Ein 429, hinter dem kein Engpass steht, sondern kein Geld. */
    private function quotaError(Response $response): ?string
    {
        if ($response->status() !== 429) {
            return null;
        }

        $code = (string) (data_get($response->json(), 'error.code') ?? data_get($response->json(), 'error.type') ?? '');

        return str_contains($code, 'insufficient_quota') || str_contains($code, 'spend_limit')
            ? $code
            : null;
    }

    /**
     * Die Notbremse ziehen, wenn OpenAI kein Geld mehr sieht.
     *
     * Beim ersten Mal bekommen die Admins eine Push-Nachricht: ab hier steht
     * jede KI-Funktion still, und das soll man nicht erst am naechsten Morgen
     * im Waechter lesen.
     */
    private function tripIfQuotaExhausted(Response $response): void
    {
        $code = $this->quotaError($response);
        if ($code === null) {
            return;
        }

        $first = Cache::add(self::BLOCK_KEY, "OpenAI meldet {$code}", now()->addMinutes(self::BLOCK_MINUTES));

        Log::error('OpenAI: Guthaben oder Ausgabenlimit erschoepft, KI-Aufrufe pausiert', [
            'code'    => $code,
            'minutes' => self::BLOCK_MINUTES,
        ]);

        if (! $first) {
            return;
        }

        try {
            $push = app(WebPushService::class);
            foreach (User::where('is_admin', true)->get() as $admin) {
                $push->sendToUser(
                    $admin,
                    'Zone3: OpenAI gestoppt',
                    "OpenAI meldet {$code}. Alle KI-Aufrufe pausieren " . self::BLOCK_MINUTES . ' Minuten. Guthaben pruefen.',
                    '/admin/ai-logs',
                );
            }
        } catch (\Throwable $e) {
            Log::warning('OpenAI-Notbremse: Admins nicht benachrichtigt', ['error' => $e->getMessage()]);
        }
    }

    /** Ein Aufruf, der wegen einer Bremse nicht stattfand — sichtbar im AI-Log. */
    private function logSkipped(string $callType, string $model, string $reason): void
    {
        AiLog::create([
            'user_id'       => $this->userId,
            'call_type'     => $callType,
            'model'         => $model,
            'status'        => 'skipped',
            'error_message' => $reason,
        ]);

        Log::warning("OpenAI {$callType} uebersprungen", ['reason' => $reason, 'user_id' => $this->userId]);
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
