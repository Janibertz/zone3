<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\User;
use App\Services\AI\OpenAIClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Die Kostenbremsen im OpenAI-Client.
 *
 * Seit dem 28.10.2026 kostet jeder Aufruf echtes Geld: das Teilen der Daten
 * mit OpenAI ist aus, das Guthaben ist begrenzt, und bei 0 $ steht jede
 * KI-Funktion still. Hier steht, was den Verbrauch klein haelt (Flex, das
 * mittlere Modell) und was ihn begrenzt (Tagesbudgets, Notbremse).
 */
class OpenAICostControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key'               => 'test-key',
            'services.openai.flex'                  => true,
            'services.openai.budget.daily_eur'      => 1.00,
            'services.openai.budget.user_daily_eur' => 0.30,
        ]);
    }

    private function client(): OpenAIClient
    {
        return app(OpenAIClient::class);
    }

    private function answer(array $extra = []): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(array_merge([
            'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
        ], $extra), 200);
    }

    private function say(OpenAIClient $client, string $callType = 'daily_message', bool $background = false): ?string
    {
        return $client->chat($callType, [['role' => 'user', 'content' => 'Hallo']], 1.0, 700, background: $background);
    }

    // ── Flex ─────────────────────────────────────────────────────────────

    public function test_a_background_call_goes_through_flex(): void
    {
        Http::fake(['api.openai.com/*' => $this->answer()]);

        $this->say($this->client(), 'session_review', background: true);

        Http::assertSent(fn (Request $r) => ($r->data()['service_tier'] ?? null) === 'flex');
    }

    /** Wer im Browser auf die Antwort wartet, bekommt Standard. */
    public function test_a_foreground_call_stays_on_standard(): void
    {
        Http::fake(['api.openai.com/*' => $this->answer()]);

        $this->say($this->client());

        Http::assertSent(fn (Request $r) => ! array_key_exists('service_tier', $r->data()));
    }

    public function test_flex_can_be_switched_off(): void
    {
        config(['services.openai.flex' => false]);
        Http::fake(['api.openai.com/*' => $this->answer()]);

        $this->say($this->client(), 'session_review', background: true);

        Http::assertSent(fn (Request $r) => ! array_key_exists('service_tier', $r->data()));
    }

    /**
     * Flex lehnt ab, wenn OpenAI gerade keine Kapazitaet hat. Dann folgt
     * genau ein Versuch auf Standard — ein Review faellt nicht aus, nur weil
     * der billige Weg voll war.
     */
    public function test_flex_without_capacity_falls_back_to_standard_once(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['code' => 'resource_unavailable', 'message' => 'Resource Unavailable']], 429)
            ->push([
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
            ], 200),
        ]);

        $text = $this->say($this->client(), 'session_review', background: true);

        $this->assertSame('OK', $text);
        Http::assertSentCount(2);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data()['service_tier'] ?? 'standard')->all();
        $this->assertSame(['flex', 'standard'], $sent);
    }

    // ── Notbremse ────────────────────────────────────────────────────────

    /**
     * Leeres Guthaben ist kein Engpass: Standard scheitert genauso. Statt
     * eines zweiten Versuchs wird die Notbremse gezogen, und jeder weitere
     * Aufruf unterbleibt — ohne Request.
     */
    public function test_an_empty_balance_trips_the_brake_instead_of_retrying(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['code' => 'insufficient_quota', 'type' => 'insufficient_quota', 'message' => 'You exceeded your current quota'],
        ], 429)]);

        $client = $this->client();

        $this->assertNull($this->say($client, 'session_review', background: true));
        Http::assertSentCount(1);
        $this->assertNotNull(Cache::get(OpenAIClient::BLOCK_KEY));

        $this->assertNull($this->say($client));
        Http::assertSentCount(1);

        $skipped = AiLog::where('status', 'skipped')->first();
        $this->assertNotNull($skipped);
        $this->assertStringContainsString('Notbremse', $skipped->error_message);
    }

    public function test_a_project_spend_limit_trips_the_brake_too(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['code' => 'project_spend_limit_exceeded', 'message' => 'Project spend limit reached'],
        ], 429)]);

        $this->say($this->client());

        $this->assertNotNull(Cache::get(OpenAIClient::BLOCK_KEY));
    }

    /** Ein gewoehnliches Ratenlimit sperrt nicht die ganze App fuer eine Stunde. */
    public function test_a_plain_rate_limit_does_not_trip_the_brake(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit reached'],
        ], 429)]);

        $this->say($this->client());

        $this->assertNull(Cache::get(OpenAIClient::BLOCK_KEY));
    }

    // ── Tagesbudgets ─────────────────────────────────────────────────────

    private function spent(?int $userId, float $eur): void
    {
        AiLog::create([
            'user_id'   => $userId,
            'call_type' => 'coach_chat',
            'model'     => 'gpt-5.6-terra',
            'cost_eur'  => $eur,
            'status'    => 'success',
        ]);
    }

    public function test_an_athlete_over_budget_gets_no_further_calls(): void
    {
        $user = User::factory()->create();
        $this->spent($user->id, 0.31);
        Http::fake(['api.openai.com/*' => $this->answer()]);

        $client = $this->client()->forUser($user->id);

        $this->assertNull($this->say($client));
        Http::assertNothingSent();
        $this->assertTrue($client->isRateLimited(), 'Der Chat sagt es dem Athleten, statt ins Leere zu laufen');
    }

    /**
     * Der Plan laeuft meist ohne Zutun des Athleten (Sonntag 19:00). Wer
     * tagsueber viel schreibt, soll dadurch nicht ohne Woche dastehen.
     */
    public function test_the_plan_is_exempt_from_the_athletes_budget(): void
    {
        $user = User::factory()->create();
        $this->spent($user->id, 0.31);
        Http::fake(['api.openai.com/*' => $this->answer()]);

        $text = $this->say($this->client()->forUser($user->id), 'event_plan', background: true);

        $this->assertSame('OK', $text);
    }

    /** Das Budget fuer alle faengt eine Schleife ab — auch eine im Plan. */
    public function test_the_global_budget_stops_everything(): void
    {
        $this->spent(null, 1.01);
        Http::fake(['api.openai.com/*' => $this->answer()]);

        $this->assertNull($this->say($this->client(), 'event_plan', background: true));
        Http::assertNothingSent();
    }

    public function test_yesterdays_spending_does_not_count(): void
    {
        $user = User::factory()->create();
        $this->spent($user->id, 5.00);
        AiLog::query()->update(['created_at' => now()->subDay()]);
        Http::fake(['api.openai.com/*' => $this->answer()]);

        $this->assertSame('OK', $this->say($this->client()->forUser($user->id)));
    }

    // ── Protokoll ────────────────────────────────────────────────────────

    public function test_cache_reasoning_and_tier_are_logged_and_priced(): void
    {
        Http::fake(['api.openai.com/*' => $this->answer([
            'service_tier' => 'flex',
            'usage'        => [
                'prompt_tokens'             => 1_000_000,
                'completion_tokens'         => 1_000_000,
                'total_tokens'              => 2_000_000,
                'prompt_tokens_details'     => ['cached_tokens' => 500_000],
                'completion_tokens_details' => ['reasoning_tokens' => 800_000],
            ],
        ])]);

        $this->client()->chat('event_plan', [['role' => 'user', 'content' => 'Plan']], 1.0, 16000, model: 'gpt-5.6-sol', background: true);

        $log = AiLog::latest('id')->first();

        $this->assertSame(500_000, $log->cached_tokens);
        $this->assertSame(800_000, $log->reasoning_tokens);
        $this->assertSame('flex', $log->service_tier);

        // Sol 4/20 $: 0,5M × 4 + 0,5M × 0,4 + 1M × 20 = 22,20 $, Flex halbiert.
        $this->assertEqualsWithDelta(22.20 * 0.5 * 0.92, $log->cost_eur, 0.01);
    }

    /** Der Coach-Chat laeuft auf dem mittleren Modell — und wird so protokolliert. */
    public function test_the_tool_call_uses_the_model_it_is_given(): void
    {
        config(['services.openai.model_mid' => 'gpt-5.6-terra']);
        Http::fake(['api.openai.com/*' => $this->answer()]);

        $client = $this->client();
        $client->chatWithTools('coach_chat', [['role' => 'user', 'content' => 'Hallo']], [
            ['type' => 'function', 'function' => ['name' => 'noop', 'parameters' => ['type' => 'object', 'properties' => []]]],
        ], model: $client->mid());

        Http::assertSent(fn (Request $r) => $r->data()['model'] === 'gpt-5.6-terra'
            && ! array_key_exists('service_tier', $r->data()));
        $this->assertSame('gpt-5.6-terra', AiLog::latest('id')->value('model'));
    }
}
