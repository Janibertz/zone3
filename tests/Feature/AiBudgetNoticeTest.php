<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\User;
use App\Services\AI\OpenAIClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Jan, 22.11.2026: „1 Euro pro Athlet pro Tag auf alle KI-Aktivitäten.
 * Wenn das Budget voll ist, Telegram oder Push-Nachricht."
 */
class AiBudgetNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openai.api_key'    => 'test-key',
            'services.telegram.bot_token' => 'TEST-TOKEN',
        ]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'api.openai.com/*'   => Http::response([
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
            ]),
        ]);
    }

    private function spent(int $userId, float $eur): void
    {
        AiLog::create(['user_id' => $userId, 'call_type' => 'session_review', 'model' => 'x', 'cost_eur' => $eur, 'status' => 'success']);
    }

    private function telegramTexts(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]->data()['text'] ?? '')
            ->values()->all();
    }

    public function test_the_default_is_one_euro(): void
    {
        $this->assertSame(1.0, OpenAIClient::userDefaultBudgetEur());
    }

    public function test_the_athlete_hears_once_a_day_when_the_budget_is_full(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 4711]);
        $this->spent($user->id, 1.01);
        $client = app(OpenAIClient::class)->forUser($user->id);

        $this->assertNull($client->chat('session_review', [['role' => 'user', 'content' => 'x']], 1.0, 700));
        $this->assertNull($client->chat('weekly_review', [['role' => 'user', 'content' => 'x']], 1.0, 700));

        $texts = $this->telegramTexts();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('1,00 €', $texts[0]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com'));
    }

    /** Der Plan zählt ins Budget, wird aber nie gesperrt — sonst fehlte die Woche. */
    public function test_the_plan_still_runs_over_budget(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 4711]);
        $this->spent($user->id, 1.01);

        $this->assertSame('OK', app(OpenAIClient::class)->forUser($user->id)->chat('event_plan', [['role' => 'user', 'content' => 'x']], 1.0, 700));
        $this->assertSame([], $this->telegramTexts());
    }

    public function test_no_notice_when_the_shared_budget_stops_everyone(): void
    {
        config(['services.openai.budget.daily_eur' => 1.00]);
        \App\Models\AppSetting::where('key', OpenAIClient::SETTING_DAILY_BUDGET)->delete();
        $other = User::factory()->create();
        $this->spent($other->id, 1.5);
        $user = User::factory()->create(['telegram_chat_id' => 4711, 'ai_daily_budget_eur' => 5]);

        app(OpenAIClient::class)->forUser($user->id)->chat('session_review', [['role' => 'user', 'content' => 'x']], 1.0, 700);

        $this->assertSame([], $this->telegramTexts());
    }
}
