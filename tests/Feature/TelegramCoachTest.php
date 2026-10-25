<?php

namespace Tests\Feature;

use App\Models\CoachMessage;
use App\Models\Event;
use App\Models\SessionRecommendation;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TelegramClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Der Coach in Telegram.
 *
 * Gewünscht: „Der Coach Chat ist zu wenig interaktiv. Es sollte WhatsApp,
 * Telegram oder ähnliches dabei sein." Diese Tests halten fest, was der Bot
 * darf und was nicht — vor allem, dass ein fremder Chat nichts erfährt und
 * dass eine Entscheidung im Chat denselben Weg nimmt wie auf dem Dashboard.
 */
class TelegramCoachTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 987654321;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-27 08:00:00'); // ein Dienstag
        config(['services.telegram.bot_token' => 'TEST-TOKEN']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'username' => 'Zone3CoachBot']]),
            'api.openai.com/*'   => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Heute **locker** bleiben.'], 'finish_reason' => 'stop']],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'total_tokens' => 70],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function athlete(bool $linked = true): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now(), 'name' => 'Jan Tester']);
        $user->runnerProfile()->create(['threshold_speed' => 4.5, 'threshold_heart_rate' => 170, 'max_heart_rate' => 190]);

        if ($linked) {
            $user->forceFill(['telegram_chat_id' => self::CHAT, 'telegram_linked_at' => now()])->save();
        }

        return $user->refresh();
    }

    /** @return array{0: User, 1: TrainingSession} */
    private function athleteWithSession(): array
    {
        $user  = $this->athlete();
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Halbmarathon', 'event_date' => now()->addDays(60),
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 30,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        $session = TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => now()->toDateString(), 'type' => 'tempo_run', 'title' => 'Schwelle 3×10 min',
            'description' => "15 min einlaufen, 3×10 min an der Schwelle\nDetails folgen", 'distance_km' => 12,
            'duration_min' => 65, 'pace_target' => '4:10', 'zone' => 4, 'intensity' => 'high', 'status' => 'planned',
        ]);

        return [$user, $session];
    }

    private function update(array $payload, ?string $secret = null): \Illuminate\Testing\TestResponse
    {
        static $id = 1000;

        return $this->postJson(route('telegram.webhook'), ['update_id' => $id++] + $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => $secret ?? app(TelegramClient::class)->webhookSecret(),
        ]);
    }

    private function text(string $text, int $chat = self::CHAT, string $type = 'private'): array
    {
        return ['message' => ['message_id' => 5, 'chat' => ['id' => $chat, 'type' => $type], 'text' => $text]];
    }

    /** @return list<array> Die Texte aller sendMessage-Aufrufe an Telegram. */
    private function sentTexts(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org') && str_ends_with($pair[0]->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    // ── Zugang ───────────────────────────────────────────────────────────

    public function test_a_call_without_the_secret_is_rejected(): void
    {
        $this->athlete();

        $this->update($this->text('Hallo'), secret: 'falsch')->assertStatus(403);

        $this->assertSame(0, CoachMessage::count());
    }

    public function test_the_start_code_links_the_chat_to_the_account(): void
    {
        $user = $this->athlete(linked: false);
        $user->forceFill(['telegram_link_token' => 'abc123'])->save();

        $this->update($this->text('/start abc123'))->assertOk();

        $user->refresh();
        $this->assertSame(self::CHAT, (int) $user->telegram_chat_id);
        $this->assertNull($user->telegram_link_token, 'Der Code gilt nur einmal');
        $this->assertStringContainsString('Verbunden', $this->sentTexts()[0]['text']);
    }

    public function test_an_unknown_chat_learns_nothing_and_reaches_no_coach(): void
    {
        $this->athlete(linked: false);

        $this->update($this->text('Wie war mein Ruhepuls?', chat: 111))->assertOk();

        $this->assertSame(0, CoachMessage::count());
        $this->assertStringContainsString('noch mit keinem Zone3-Konto verbunden', $this->sentTexts()[0]['text']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com'));
    }

    /** In einer Gruppe läsen andere mit — dort antwortet der Bot gar nicht. */
    public function test_group_chats_are_ignored(): void
    {
        $this->athlete();

        $this->update($this->text('Hallo', type: 'group'))->assertOk();

        $this->assertSame(0, CoachMessage::count());
        $this->assertSame([], $this->sentTexts());
    }

    // ── Der Coach antwortet im Chat ──────────────────────────────────────

    public function test_a_message_gets_the_coach_reply_in_telegram(): void
    {
        $user = $this->athlete();

        $this->update($this->text('Wie soll ich heute laufen?'))->assertOk();

        $this->assertDatabaseHas('coach_messages', ['user_id' => $user->id, 'role' => 'user', 'channel' => 'telegram']);

        $reply = CoachMessage::where('role', 'assistant')->first();
        $this->assertSame('telegram', $reply->channel);
        $this->assertNotNull($reply->reply_to_id);

        $texts = array_column($this->sentTexts(), 'text');
        $this->assertContains('Heute <b>locker</b> bleiben.', $texts, 'Fettdruck kommt als HTML an');
    }

    /** Telegram stellt nach einer Zeitüberschreitung erneut zu — der Coach antwortet trotzdem nur einmal. */
    public function test_a_repeated_update_is_handled_once(): void
    {
        $this->athlete();
        $payload = ['update_id' => 42] + $this->text('Hallo');
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => app(TelegramClient::class)->webhookSecret()];

        $this->postJson(route('telegram.webhook'), $payload, $headers)->assertOk();
        $this->postJson(route('telegram.webhook'), $payload, $headers)->assertOk();

        $this->assertSame(1, CoachMessage::where('role', 'user')->count());
    }

    public function test_heute_shows_the_session_without_asking_the_model(): void
    {
        $this->athleteWithSession();

        $this->update($this->text('/heute'))->assertOk();

        $this->assertStringContainsString('Schwelle 3×10 min · 12 km · 65 min · 4:10', $this->sentTexts()[0]['text']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com'));
    }

    // ── Benachrichtigungen ───────────────────────────────────────────────

    /** Ein Review vom System geht nach Telegram — und dann nicht zusätzlich als Push. */
    public function test_a_coach_message_goes_to_telegram_instead_of_push(): void
    {
        $user = $this->athlete();
        $user->forceFill(['push_notifications_enabled' => true])->save();

        CoachMessage::create(['user_id' => $user->id, 'role' => 'assistant', 'content' => 'Starker Lauf heute!']);

        $this->assertSame('Starker Lauf heute!', $this->sentTexts()[0]['text']);
    }

    /** Die Antwort auf eine Frage aus dem Web-Chat bleibt im Web. */
    public function test_a_web_chat_reply_stays_in_the_web(): void
    {
        $user = $this->athlete();

        CoachMessage::create(['user_id' => $user->id, 'role' => 'assistant', 'content' => 'Antwort', 'channel' => 'web']);

        $this->assertSame([], $this->sentTexts());
    }

    public function test_the_morning_briefing_arrives_at_the_reminder_time(): void
    {
        [$user] = $this->athleteWithSession();
        $user->forceFill(['wellbeing_reminder_time' => '08:00'])->save();

        $this->artisan('telegram:briefing')->assertSuccessful();
        $this->artisan('telegram:briefing')->assertSuccessful();

        $texts = array_column($this->sentTexts(), 'text');
        $this->assertCount(1, $texts, 'Einmal am Tag, auch wenn der Scheduler doppelt läuft');
        $this->assertStringContainsString('Guten Morgen, Jan!', $texts[0]);
        $this->assertStringContainsString('Schwelle 3×10 min', $texts[0]);
    }

    public function test_no_briefing_at_another_time(): void
    {
        [$user] = $this->athleteWithSession();
        $user->forceFill(['wellbeing_reminder_time' => '07:00'])->save();

        $this->artisan('telegram:briefing')->assertSuccessful();

        $this->assertSame([], $this->sentTexts());
    }

    // ── Vorschläge mit Knöpfen ───────────────────────────────────────────

    private function recommendation(User $user, TrainingSession $session): SessionRecommendation
    {
        return SessionRecommendation::create([
            'user_id' => $user->id, 'training_session_id' => $session->id,
            'source' => SessionRecommendation::SOURCE_WELLBEING, 'reason' => 'HRV 25 % unter der Grundlinie.',
            'before' => ['type' => 'tempo_run', 'title' => 'Schwelle 3×10 min', 'distance_km' => 12],
            'after'  => ['type' => 'easy_run', 'title' => 'Locker statt Schwelle', 'description' => 'Heute ruhig.',
                'distance_km' => 8, 'duration_min' => 45, 'pace_target' => '5:30', 'zone' => 2, 'intensity' => 'low'],
        ]);
    }

    public function test_a_recommendation_arrives_with_two_buttons(): void
    {
        [$user, $session] = $this->athleteWithSession();
        $rec = $this->recommendation($user, $session);

        $sent = $this->sentTexts()[0];
        $this->assertStringContainsString('Locker statt Schwelle · 8 km', $sent['text']);
        $this->assertSame("rec:accept:{$rec->id}", $sent['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame("rec:reject:{$rec->id}", $sent['reply_markup']['inline_keyboard'][0][1]['callback_data']);
    }

    public function test_accepting_in_telegram_changes_and_pins_the_session(): void
    {
        [$user, $session] = $this->athleteWithSession();
        $rec = $this->recommendation($user, $session);

        $this->update(['callback_query' => [
            'id' => 'cb1', 'data' => "rec:accept:{$rec->id}",
            'message' => ['message_id' => 77, 'chat' => ['id' => self::CHAT, 'type' => 'private']],
        ]])->assertOk();

        $session->refresh();
        $this->assertSame('easy_run', $session->type);
        $this->assertNotNull($session->pinned_at, 'Eine Entscheidung des Athleten überlebt die Neuberechnung');
        $this->assertSame(SessionRecommendation::STATUS_ACCEPTED, $rec->refresh()->status);
    }

    /** Ein fremder Chat kann über einen erratenen Knopf nichts entscheiden. */
    public function test_a_foreign_chat_cannot_decide(): void
    {
        [$user, $session] = $this->athleteWithSession();
        $rec = $this->recommendation($user, $session);

        $other = User::factory()->create();
        $other->forceFill(['telegram_chat_id' => 555])->save();

        $this->update(['callback_query' => [
            'id' => 'cb2', 'data' => "rec:accept:{$rec->id}",
            'message' => ['message_id' => 78, 'chat' => ['id' => 555, 'type' => 'private']],
        ]])->assertOk();

        $this->assertSame('tempo_run', $session->refresh()->type);
        $this->assertTrue($rec->refresh()->isOpen());
    }

    // ── Profil ───────────────────────────────────────────────────────────

    public function test_connecting_opens_the_bot_with_a_one_time_code(): void
    {
        $user = $this->athlete(linked: false);

        $response = $this->actingAs($user)
            ->post(route('telegram.link'), [], ['X-Inertia' => 'true'])
            ->assertStatus(409);

        $token = $user->refresh()->telegram_link_token;
        $this->assertNotNull($token);
        $this->assertSame("https://t.me/Zone3CoachBot?start={$token}", $response->headers->get('X-Inertia-Location'));
    }

    public function test_disconnecting_clears_the_chat(): void
    {
        $user = $this->athlete();

        $this->actingAs($user)->delete(route('telegram.unlink'))->assertRedirect();

        $this->assertNull($user->refresh()->telegram_chat_id);
    }
}
