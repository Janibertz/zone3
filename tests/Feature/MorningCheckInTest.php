<?php

namespace Tests\Feature;

use App\Models\GarminDailyMetric;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Morgens fragt Zone3 nach der Tagesform: per Telegram im Briefing, ohne
 * Telegram per Push. Wer Garmin hat, sieht dazu die Werte der Nacht.
 */
class MorningCheckInTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 4242;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-27 08:00:00');
        config(['services.telegram.bot_token' => 'TEST-TOKEN']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function athlete(bool $telegram = true, bool $garmin = false): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now(), 'name' => 'Jan Tester']);
        $user->runnerProfile()->create(['threshold_speed' => 4.5]);

        $user->forceFill(array_filter([
            'telegram_chat_id' => $telegram ? self::CHAT : null,
            'garmin_session'   => $garmin ? 'token' : null,
        ]))->save();

        return $user->refresh();
    }

    private function metric(User $user, int $daysAgo, ?float $hrv, ?int $rhr, ?float $sleep = 7.5, ?int $battery = 80): void
    {
        GarminDailyMetric::create([
            'user_id'            => $user->id,
            'date'               => now()->subDays($daysAgo)->toDateString(),
            'hrv'                => $hrv,
            'resting_hr'         => $rhr,
            'sleep_hours'        => $sleep,
            'sleep_score'        => $sleep !== null ? 82 : null,
            'body_battery_high'  => $battery,
            'training_readiness' => 70,
        ]);
    }

    /** @return array{text: string, reply_markup?: array} */
    private function briefing(): array
    {
        $this->artisan('telegram:briefing')->assertSuccessful();

        $sent = collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values();

        $this->assertCount(1, $sent);

        return $sent[0];
    }

    // ── Telegram ─────────────────────────────────────────────────────────

    /** Auch ohne Plan, Ruhetag oder Pause kommt die Frage — vorher brach die Nachricht davor ab. */
    public function test_the_briefing_asks_even_without_a_session(): void
    {
        $this->athlete();

        $msg = $this->briefing();

        $this->assertStringContainsString('Du hast noch keinen aktiven Trainingsplan.', $msg['text']);
        $this->assertStringContainsString('Tagesform', $msg['text']);
        $this->assertSame('Tagesform eintragen', $msg['reply_markup']['inline_keyboard'][0][0]['text']);
        $this->assertStringEndsWith('/dashboard?tagesform=1', $msg['reply_markup']['inline_keyboard'][0][0]['url']);
    }

    public function test_the_briefing_asks_during_a_pause(): void
    {
        $user = $this->athlete();
        $user->runnerProfile->forceFill(['training_paused_until' => '2026-11-08'])->save();

        $msg = $this->briefing();

        $this->assertStringContainsString('Trainingspause bis 08.11.', $msg['text']);
        $this->assertStringContainsString('Tagesform', $msg['text']);
    }

    public function test_no_question_once_checked_in(): void
    {
        $user = $this->athlete();
        WellbeingEntry::create(['user_id' => $user->id, 'date' => now()->toDateString(), 'energy_level' => 4, 'mood' => 4, 'sleep_quality' => 4, 'muscle_soreness' => 2, 'stress_level' => 2]);

        $msg = $this->briefing();

        $this->assertStringNotContainsString('Tagesform', $msg['text']);
        $this->assertArrayNotHasKey('reply_markup', $msg);
    }

    public function test_the_garmin_values_of_the_night(): void
    {
        $user = $this->athlete(garmin: true);
        foreach (range(1, 14) as $d) {
            $this->metric($user, $d, 60, 50);
        }
        $this->metric($user, 0, 61, 50, 7.2, 85);

        $text = $this->briefing()['text'];

        $this->assertStringContainsString('Deine Nacht', $text);
        $this->assertStringContainsString('HRV 61 ms · normal', $text);
        $this->assertStringContainsString('Ruhepuls 50 bpm · normal', $text);
        $this->assertStringContainsString('Schlaf 7,2 h (Score 82)', $text);
        $this->assertStringContainsString('Body Battery 85', $text);
        $this->assertStringNotContainsString('Readiness', $text);
        $this->assertStringNotContainsString('weichen', $text);
    }

    public function test_notable_values_get_a_hint(): void
    {
        $user = $this->athlete(garmin: true);
        foreach (range(1, 14) as $d) {
            $this->metric($user, $d, 60, 50);
        }
        $this->metric($user, 0, 48, 56, 7.5, 80);

        $text = $this->briefing()['text'];

        $this->assertStringContainsString('HRV 48 ms · -20 % zu deinem Schnitt', $text);
        $this->assertStringContainsString('Ruhepuls 56 bpm · +12 % zu deinem Schnitt', $text);
        $this->assertStringContainsString('Hör beim Training besonders auf dich.', $text);
    }

    /** Schlaf kommt erst nach dem Sync der Uhr — dann fehlt die Zeile, das Briefing wartet nicht. */
    public function test_missing_values_are_left_out(): void
    {
        $user = $this->athlete(garmin: true);
        $this->metric($user, 0, 60, null, null, null);

        $text = $this->briefing()['text'];

        $this->assertStringContainsString('HRV 60 ms', $text);
        $this->assertStringNotContainsString('Schlaf', $text);
        $this->assertStringNotContainsString('Ruhepuls', $text);
    }

    public function test_no_garmin_block_without_garmin(): void
    {
        $user = $this->athlete();
        $this->metric($user, 0, 60, 50);

        $this->assertStringNotContainsString('Deine Nacht', $this->briefing()['text']);
    }

    /** /heute zeigt nur die Einheit, ohne Frage und Werte. */
    public function test_the_today_command_stays_short(): void
    {
        $user = $this->athlete(garmin: true);
        $this->metric($user, 0, 60, 50);

        $text = app(\App\Services\TelegramBriefing::class)->today($user);

        $this->assertSame('Du hast noch keinen aktiven Trainingsplan.', $text);
    }

    // ── Push ─────────────────────────────────────────────────────────────

    private function pushAthlete(?string $time): User
    {
        $user = $this->athlete(telegram: false);
        $user->forceFill(['push_notifications_enabled' => true, 'wellbeing_reminder_time' => $time])->save();
        PushSubscription::create(['user_id' => $user->id, 'endpoint' => 'https://push.example/' . $user->id, 'public_key' => 'p', 'auth_token' => 'a']);

        return $user;
    }

    /** Ohne eingestellte Uhrzeit gilt 08:00 wie beim Telegram-Briefing — vorher kam gar nichts. */
    public function test_the_push_falls_back_to_eight(): void
    {
        $this->pushAthlete(null);

        $this->mock(WebPushService::class, fn ($m) => $m->shouldReceive('sendToUser')->once()
            ->withArgs(fn ($user, $title, $body, $url) => $url === '/dashboard?tagesform=1'));

        $this->artisan('push:wellbeing-reminders')->assertSuccessful();
    }

    public function test_no_push_once_checked_in_or_with_telegram(): void
    {
        $user = $this->pushAthlete('08:00');
        WellbeingEntry::create(['user_id' => $user->id, 'date' => now()->toDateString(), 'energy_level' => 4, 'mood' => 4, 'sleep_quality' => 4, 'muscle_soreness' => 2, 'stress_level' => 2]);

        $other = $this->pushAthlete('08:00');
        $other->forceFill(['telegram_chat_id' => self::CHAT])->save();

        $this->mock(WebPushService::class, fn ($m) => $m->shouldNotReceive('sendToUser'));

        $this->artisan('push:wellbeing-reminders')->assertSuccessful();
    }

    public function test_no_push_at_another_time(): void
    {
        $this->pushAthlete('07:00');

        $this->mock(WebPushService::class, fn ($m) => $m->shouldNotReceive('sendToUser'));

        $this->artisan('push:wellbeing-reminders')->assertSuccessful();
    }
}
