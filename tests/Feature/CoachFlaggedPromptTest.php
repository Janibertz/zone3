<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\User;
use App\Services\AI\CoachChatService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gemeldet am 22.11.2026: eine harmlose Frage im Coach-Chat kam von OpenAI
 * mit HTTP 400 „invalid_prompt“ zurück (Inhaltsfilter), und der Athlet las
 * „Entschuldigung, ich konnte gerade nicht antworten“.
 */
class CoachFlaggedPromptTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<bool> je Aufruf: true = abgelehnt */
    private array $flags = [];

    /** @var list<array> die gesendeten Nachrichten je Aufruf */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 08:20:00');
        config(['services.openai.api_key' => 'test']);

        Http::fake(['api.openai.com/v1/chat/completions' => function (Request $r) {
            $this->sent[] = $r->data()['messages'];

            if (array_shift($this->flags)) {
                return Http::response(['error' => [
                    'message' => 'Invalid prompt: your prompt was flagged as potentially violating our usage policy.',
                    'type'    => 'invalid_request_error',
                    'param'   => null,
                    'code'    => 'invalid_prompt',
                ]], 400);
            }

            return Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Klar, Warmup per Lap-Taste.'], 'finish_reason' => 'stop']],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'total_tokens' => 70],
            ]);
        }]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ask(array $flags): array
    {
        $this->flags = $flags;
        $user        = User::factory()->onboarded()->create();

        return app(CoachChatService::class)->chatWithCoachTools($user, [
            ['role' => 'user', 'content' => 'Alte Frage'],
            ['role' => 'assistant', 'content' => 'Alte Antwort'],
        ], 'Das Warmup will ich durch die Lap Taste beenden.');
    }

    public function test_a_flagged_prompt_is_retried_without_the_history(): void
    {
        $result = $this->ask([true, false]);

        $this->assertSame('Klar, Warmup per Lap-Taste.', $result['reply']);
        $this->assertCount(2, $this->sent);
        $this->assertCount(4, $this->sent[0]);
        $this->assertCount(2, $this->sent[1], 'zweiter Versuch: nur Systemprompt und Frage');
        $this->assertStringContainsString('ATHLETEN-DATEN', $this->sent[1][0]['content']);
    }

    public function test_the_second_retry_also_drops_the_athlete_data(): void
    {
        $result = $this->ask([true, true, false]);

        $this->assertSame('Klar, Warmup per Lap-Taste.', $result['reply']);
        $this->assertCount(3, $this->sent);
        $this->assertStringNotContainsString('ATHLETEN-DATEN', $this->sent[2][0]['content']);
        $this->assertStringContainsString('2026-11-22', $this->sent[2][0]['content']);
    }

    public function test_the_athlete_gets_a_clear_message_when_every_attempt_is_flagged(): void
    {
        $result = $this->ask([true, true, true, true]);

        $this->assertSame(CoachChatService::FLAGGED_REPLY, $result['reply']);
        $this->assertCount(3, $this->sent, 'höchstens zwei neue Versuche');
    }

    public function test_the_failed_call_keeps_the_whole_prompt_for_diagnosis(): void
    {
        $this->ask([true, false]);

        $log = AiLog::where('status', 'error')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('ATHLETEN-DATEN', $log->full_prompt);
        $this->assertStringContainsString('invalid_prompt', $log->full_response);
    }
}
