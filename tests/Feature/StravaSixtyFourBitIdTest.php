<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\StravaAccount;
use App\Models\StravaWebhookEvent;
use App\Models\User;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Strava vergibt ab dem 01.06.2027 64-Bit-IDs für Aktivitäten und Athleten
 * (Mail an die Entwickler vom November 2026).
 *
 * PHP (64 Bit) und die Spalten (`bigInteger`, Athlet als Text) kommen damit
 * klar. Kaputt ginge nur JavaScript: eine Zahl ist dort nur bis 2^53 genau,
 * und der Link „Auf Strava ansehen“ zeigte dann auf eine fremde Aktivität.
 * Deshalb geht die ID als Text ans Frontend.
 */
class StravaSixtyFourBitIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** Größer als 2^53 und nahe an der oberen Grenze eines signed int64. */
    private const ACTIVITY_ID = 9123456789012345678;
    private const ATHLETE_ID = 9007199254740993; // 2^53 + 1 — als JS-Zahl 9007199254740992

    private function activity(User $user): Activity
    {
        return Activity::create([
            'user_id' => $user->id, 'strava_id' => self::ACTIVITY_ID, 'name' => 'Abendlauf',
            'type' => 'Run', 'start_date' => now()->subDay(), 'distance' => 12000,
            'moving_time' => 3300, 'elapsed_time' => 3300, 'average_speed' => 12000 / 3300,
        ]);
    }

    public function test_the_webhook_stores_a_64_bit_id_exactly(): void
    {
        config([
            'services.strava.client_id'            => 'test-client',
            'services.strava.client_secret'        => 'test-secret',
            'services.strava.redirect'             => 'https://zone3.test/strava/callback',
            'services.strava.webhook_verify_token' => 'test-verify-token',
        ]);

        $user = User::factory()->onboarded()->create();
        StravaAccount::create([
            'user_id' => $user->id, 'strava_id' => (string) self::ATHLETE_ID,
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_expires_at' => now()->addDay(),
        ]);

        Queue::fake();
        Http::preventStrayRequests();
        // Rohes JSON wie von Strava: die Zahl steht unverändert im Text,
        // `id_str` daneben.
        $body = '{"id":' . self::ACTIVITY_ID . ',"id_str":"' . self::ACTIVITY_ID . '",'
            . '"athlete":{"id":' . self::ATHLETE_ID . ',"id_str":"' . self::ATHLETE_ID . '"},'
            . '"name":"Abendlauf","type":"Run","distance":12000,"moving_time":3300,'
            . '"elapsed_time":3300,"average_speed":3.64,"start_date":"' . now()->toIso8601String() . '"}';
        Http::fake([
            'www.strava.com/api/v3/activities/*' => Http::response($body, 200, ['Content-Type' => 'application/json']),
        ]);

        $this->call('POST', '/strava/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            '{"object_type":"activity","aspect_type":"create","owner_id":' . self::ATHLETE_ID
            . ',"object_id":' . self::ACTIVITY_ID . '}')
            ->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/activities/' . self::ACTIVITY_ID));

        $activity = Activity::where('user_id', $user->id)->sole();
        $this->assertSame(self::ACTIVITY_ID, (int) $activity->strava_id);

        $event = StravaWebhookEvent::latest('id')->first();
        $this->assertSame(self::ATHLETE_ID, (int) $event->owner_id);
        $this->assertSame(self::ACTIVITY_ID, (int) $event->object_id);
    }

    public function test_the_activity_page_gets_the_id_as_text(): void
    {
        $user = User::factory()->onboarded()->create();
        $activity = $this->activity($user);

        $props = $this->actingAs($user)->get("/activities/{$activity->id}")->viewData('page')['props'];

        $this->assertSame('9123456789012345678', $props['activity']['strava_id']);
    }

    public function test_an_activity_without_strava_has_no_id(): void
    {
        $user = User::factory()->onboarded()->create();
        $activity = $this->activity($user);
        $activity->update(['strava_id' => null]);

        $props = $this->actingAs($user)->get("/activities/{$activity->id}")->viewData('page')['props'];

        $this->assertNull($props['activity']['strava_id']);
    }

    public function test_the_admin_list_gets_the_id_as_text(): void
    {
        $this->activity(User::factory()->create());

        $props = $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin/activities')->viewData('page')['props'];

        $this->assertSame('9123456789012345678', $props['activities']['data'][0]['strava_id']);
    }

    public function test_the_webhook_log_gets_the_owner_as_text(): void
    {
        StravaWebhookEvent::create([
            'object_type' => 'activity', 'aspect_type' => 'create',
            'owner_id' => self::ATHLETE_ID, 'object_id' => self::ACTIVITY_ID,
        ]);

        $hits = app(SystemHealth::class)->webhookHits();

        $this->assertSame('9007199254740993', $hits['recent'][0]['owner']);
    }
}
