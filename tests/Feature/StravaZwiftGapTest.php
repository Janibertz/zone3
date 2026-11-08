<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GarminConnection;
use App\Models\StravaAccount;
use App\Models\StravaWebhookEvent;
use App\Models\User;
use App\Services\StravaImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Strava als Lückenfüller für Zwift (Jan, 08.11.2026).
 *
 * Garmin gibt eine Fahrt, die Zwift in Garmin Connect hochlädt, nicht
 * weiter — am 08.11. kamen für eine 40-min-Zwift-Fahrt nur `dailies` und
 * `stressDetails`. Bei Quelle Garmin übernimmt Strava deshalb genau die
 * Zwift-Uploads. Alles von der Uhr kommt weiter nur über Garmin.
 */
class StravaZwiftGapTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.strava.client_id'     => 'test-client',
            'services.strava.client_secret' => 'test-secret',
            'services.strava.redirect'      => 'https://zone3.test/strava/callback',
        ]);

        Queue::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-08 15:00:00');

        $this->user = User::factory()->onboarded()->create(['activity_source' => Activity::SOURCE_GARMIN]);

        StravaAccount::create([
            'user_id' => $this->user->id, 'strava_id' => 4711,
            'access_token' => 'tok', 'refresh_token' => 'ref',
            'token_expires_at' => now()->addDay(),
        ]);

        GarminConnection::create([
            'user_id' => $this->user->id, 'garmin_user_id' => 'g-1',
            'access_token' => 'a', 'refresh_token' => 'r',
            'access_token_expires_at' => now()->addHours(20),
            'permissions' => [GarminConnection::ACTIVITY],
            'ai_consent_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function zwiftRide(array $overrides = []): array
    {
        return array_merge([
            'id' => 555, 'name' => 'Zwift - Watopia', 'type' => 'VirtualRide', 'sport_type' => 'VirtualRide',
            'distance' => 15000, 'moving_time' => 2400, 'elapsed_time' => 2400,
            'average_speed' => 6.25, 'start_date' => now()->subMinutes(45)->toIso8601String(),
            'external_id' => 'zwift-activity-1234567890.fit', 'device_name' => 'Zwift',
        ], $overrides);
    }

    private function watchRun(): array
    {
        return [
            'id' => 666, 'name' => 'Hannover Laufen', 'type' => 'Run',
            'distance' => 10000, 'moving_time' => 3000, 'elapsed_time' => 3000,
            'average_speed' => 3.33, 'start_date' => now()->subHours(3)->toIso8601String(),
            'external_id' => 'garmin_push_123456', 'device_name' => 'Garmin Forerunner 965',
        ];
    }

    public function test_only_zwift_uploads_are_accepted_for_a_garmin_athlete(): void
    {
        $importer = app(StravaImportService::class);

        $this->assertTrue($importer->accepts($this->user, $this->zwiftRide()));
        $this->assertTrue($importer->accepts($this->user, $this->zwiftRide(['external_id' => null])));
        $this->assertFalse($importer->accepts($this->user, $this->watchRun()));
        // Eine Zwift-Fahrt, die die Uhr mitgeschrieben hat, liefert Garmin selbst.
        $this->assertFalse($importer->accepts($this->user, $this->zwiftRide(['external_id' => 'garmin_push_99'])));
        // Andere Apps (MyWhoosh, Rouvy …) bleiben draußen — Jan wollte nur Zwift.
        $this->assertFalse($importer->accepts($this->user, $this->zwiftRide(['external_id' => 'mywhoosh_1.fit', 'device_name' => 'MyWhoosh'])));
    }

    public function test_a_strava_athlete_still_gets_everything(): void
    {
        $this->user->forceFill(['activity_source' => Activity::SOURCE_STRAVA])->save();

        $this->assertTrue(app(StravaImportService::class)->accepts($this->user->fresh(), $this->watchRun()));
    }

    public function test_the_webhook_imports_a_zwift_ride_for_a_garmin_athlete(): void
    {
        Http::fake(['www.strava.com/api/v3/activities/*' => Http::response($this->zwiftRide())]);

        $this->postJson('/strava/webhook', [
            'object_type' => 'activity', 'aspect_type' => 'create',
            'owner_id' => 4711, 'object_id' => 555,
        ])->assertOk();

        $activity = Activity::sole();
        $this->assertSame('VirtualRide', $activity->type);
        $this->assertSame(Activity::SOURCE_STRAVA, $activity->source);
        $this->assertSame(StravaWebhookEvent::OUTCOME_IMPORTED, StravaWebhookEvent::first()->outcome);
    }

    public function test_the_webhook_leaves_a_watch_run_to_garmin(): void
    {
        Http::fake(['www.strava.com/api/v3/activities/*' => Http::response($this->watchRun())]);

        $this->postJson('/strava/webhook', [
            'object_type' => 'activity', 'aspect_type' => 'create',
            'owner_id' => 4711, 'object_id' => 666,
        ])->assertOk();

        $this->assertSame(0, Activity::count());
        $this->assertSame(StravaWebhookEvent::OUTCOME_OTHER_SOURCE, StravaWebhookEvent::first()->outcome);
    }

    public function test_the_scheduled_sync_fetches_the_missing_zwift_ride(): void
    {
        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response([
                array_diff_key($this->zwiftRide(), ['device_name' => 1]),
                array_diff_key($this->watchRun(), ['device_name' => 1]),
            ]),
            'www.strava.com/api/v3/activities/555*' => Http::response($this->zwiftRide()),
        ]);

        $this->artisan('strava:sync')->assertSuccessful();

        $this->assertSame(['Zwift - Watopia'], Activity::pluck('name')->all());
        // Der Lauf von der Uhr kostet keinen Detailaufruf.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/activities/666'));
    }
}
