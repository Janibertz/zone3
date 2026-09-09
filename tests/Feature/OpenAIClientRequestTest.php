<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Services\AI\OpenAIClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Wie der Request an OpenAI aussehen muss.
 *
 * Diese Zusicherungen kann kein Unit-Test „richtig" beweisen — sie stehen
 * hier, weil OpenAI sie uns beigebracht hat und niemand sie beim Aufraeumen
 * versehentlich entfernen soll.
 *
 * Der Anlass: nach dem Wechsel auf gpt-5.6 antwortete der Coach-Chat mit
 * HTTP 400.
 *
 *   "Function tools with reasoning_effort are not supported for gpt-5.6-sol
 *    in /v1/chat/completions. To use function tools, use /v1/responses or
 *    set reasoning_effort to 'none'."
 *
 * Aufgefallen ist das nur, weil der Wechsel von Hand nachgeprueft wurde —
 * im Betrieb waere es nachts um 05:00 im Plan-Job passiert.
 */
class OpenAIClientRequestTest extends TestCase
{
    use RefreshDatabase;

    private function client(): OpenAIClient
    {
        config(['services.openai.api_key' => 'test-key']);

        return app(OpenAIClient::class);
    }

    private function fakeAnswer(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
            ], 200),
        ]);
    }

    /**
     * Die eine Zeile, an der der Coach-Chat haengt. Ohne sie: HTTP 400.
     */
    public function test_a_tool_call_switches_reasoning_off(): void
    {
        $this->fakeAnswer();

        $this->client()->chatWithTools('coach_chat', [
            ['role' => 'user', 'content' => 'Hallo'],
        ], [
            ['type' => 'function', 'function' => ['name' => 'noop', 'parameters' => ['type' => 'object', 'properties' => []]]],
        ]);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_contains($request->url(), '/chat/completions')
                && ($body['reasoning_effort'] ?? null) === 'none'
                && ! empty($body['tools']);
        });
    }

    /**
     * Ohne Tools gilt die Einschraenkung nicht — dort soll das Modell
     * denken duerfen, und genau das ist bei der Planerstellung der Punkt.
     */
    public function test_a_plain_call_leaves_reasoning_alone(): void
    {
        $this->fakeAnswer();

        $this->client()->chat('daily_message', [
            ['role' => 'user', 'content' => 'Hallo'],
        ], 1.0, 700);

        Http::assertSent(fn (Request $request) => ! array_key_exists('reasoning_effort', $request->data()));
    }

    /**
     * `temperature` wird von den Reasoning-Modellen nicht unterstuetzt und
     * darf deshalb nicht im Request landen — auch nicht, wenn der Aufrufer
     * einen Wert uebergibt.
     */
    public function test_temperature_never_reaches_the_api(): void
    {
        $this->fakeAnswer();

        $this->client()->chat('daily_message', [
            ['role' => 'user', 'content' => 'Hallo'],
        ], 0.7, 700);

        Http::assertSent(fn (Request $request) => ! array_key_exists('temperature', $request->data()));
    }

    /** Das Budget heisst max_completion_tokens, nicht max_tokens. */
    public function test_the_token_budget_uses_the_right_field(): void
    {
        $this->fakeAnswer();

        $this->client()->chat('daily_message', [
            ['role' => 'user', 'content' => 'Hallo'],
        ], 1.0, 1234);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return ($body['max_completion_tokens'] ?? null) === 1234
                && ! array_key_exists('max_tokens', $body);
        });
    }

    /** Jeder Aufruf landet im Log — sonst sieht niemand, was er gekostet hat. */
    public function test_every_call_is_logged(): void
    {
        $this->fakeAnswer();

        $this->client()->chat('daily_message', [['role' => 'user', 'content' => 'Hallo']], 1.0, 700);

        $log = AiLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('daily_message', $log->call_type);
        $this->assertSame(config('services.openai.model'), $log->model);
    }
}
