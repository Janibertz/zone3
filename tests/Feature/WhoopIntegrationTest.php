<?php

namespace Tests\Feature;

use App\Jobs\ImportWhoopWorkoutJob;
use App\Jobs\SyncWhoopJob;
use App\Models\Activity;
use App\Models\GarminDailyMetric;
use App\Models\IgnoredActivity;
use App\Models\PolarConnection;
use App\Models\User;
use App\Models\WhoopConnection;
use App\Services\Whoop\WhoopActivityImporter;
use App\Services\Whoop\WhoopClient;
use App\Services\Whoop\WhoopHealthImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WHOOP Developer API v2. Die Antworten haben den Aufbau aus der Doku
 * (developer.whoop.com); ein echter Aufruf stand beim Bau noch aus.
 */
class WhoopIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-13 12:00:00';

    private const API = 'https://api.prod.whoop.com/developer';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        Queue::fake();
        config([
            'services.whoop.client_id'     => 'cid',
            'services.whoop.client_secret' => 'secret',
            'services.whoop.redirect'      => 'https://zone3.run/whoop/callback',
            'services.whoop.api_url'       => self::API,
        ]);

        $this->user = User::factory()->onboarded()->create(['activity_source' => 'whoop']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function connect(array $extra = []): WhoopConnection
    {
        return WhoopConnection::create($extra + [
            'user_id' => $this->user->id, 'whoop_user_id' => 10129, 'access_token' => 'tok', 'refresh_token' => 'ref',
            'expires_at' => now()->addHour(), 'ai_consent_at' => now(),
        ]);
    }

    private function workout(string $id = 'ecfc6a15-4661-442f-a9a4-f160dd7afae8'): array
    {
        return [
            'id' => $id, 'user_id' => 10129,
            'start' => '2026-11-13T10:00:00.000Z', 'end' => '2026-11-13T10:40:00.000Z', 'timezone_offset' => '+01:00',
            'sport_name' => 'running', 'sport_id' => 0, 'score_state' => 'SCORED',
            'score' => ['strain' => 11.2, 'average_heart_rate' => 148, 'max_heart_rate' => 172, 'kilojoule' => 1900,
                'distance_meter' => 8000, 'altitude_gain_meter' => 42.4],
        ];
    }

    private function signed(array $body, string $secret = 'secret', ?int $ms = null): array
    {
        $raw = json_encode($body);
        $ts  = (string) ($ms ?? now()->getTimestampMs());

        return [$raw, [
            'X-WHOOP-Signature'           => base64_encode(hash_hmac('sha256', $ts . $raw, $secret, true)),
            'X-WHOOP-Signature-Timestamp' => $ts,
            'Content-Type'                => 'application/json',
        ]];
    }

    private function postWebhook(array $body, array $headers)
    {
        return $this->call('POST', '/whoop/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body[0] ?? null);
    }

    public function test_connecting_needs_consent(): void
    {
        $this->actingAs($this->user)->post(route('whoop.connect'), [])->assertSessionHasErrors('ai_consent');
        $this->assertDatabaseCount('whoop_connections', 0);
    }

    public function test_connect_asks_for_offline_access(): void
    {
        $response = $this->actingAs($this->user)->post(route('whoop.connect'), ['ai_consent' => true], ['X-Inertia' => 'true']);

        $location = $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://api.prod.whoop.com/oauth/oauth2/auth?', $location);
        $this->assertStringContainsString('scope=offline', $location);
    }

    public function test_the_callback_stores_the_connection(): void
    {
        Http::fake([
            'api.prod.whoop.com/oauth/*'             => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 3600, 'scope' => 'offline read:sleep']),
            self::API . '/v2/user/profile/basic'     => Http::response(['user_id' => 10129, 'first_name' => 'Jan']),
        ]);
        Cache::put('whoop-oauth:st', ['user_id' => $this->user->id], now()->addMinutes(10));

        $this->actingAs($this->user)->get('/whoop/callback?state=st&code=abc')->assertRedirect();

        $connection = $this->user->fresh()->whoopConnection;
        $this->assertSame(10129, (int) $connection->whoop_user_id);
        $this->assertSame('ref', $connection->refresh_token);
        $this->assertNotNull($connection->ai_consent_at);
        $this->assertSame('whoop', $this->user->fresh()->activitySource());
        Queue::assertPushed(SyncWhoopJob::class);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/oauth/oauth2/token')
            && $r['grant_type'] === 'authorization_code' && $r['client_secret'] === 'secret');
    }

    public function test_an_expired_token_is_refreshed_and_the_new_refresh_token_kept(): void
    {
        $connection = $this->connect(['expires_at' => now()->subMinute()]);
        Http::fake([
            'api.prod.whoop.com/oauth/*' => Http::response(['access_token' => 'tok2', 'refresh_token' => 'ref2', 'expires_in' => 3600]),
        ]);

        $this->assertSame('tok2', app(WhoopClient::class)->token($connection));
        $this->assertSame('ref2', $connection->fresh()->refresh_token);
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'ref' && $r['scope'] === 'offline');
    }

    public function test_a_workout_becomes_an_activity(): void
    {
        $this->connect();
        Http::fake([self::API . '/v2/activity/workout*' => Http::response(['records' => [$this->workout()], 'next_token' => null])]);

        $result = app(WhoopActivityImporter::class)->sync($this->user->fresh());

        $this->assertSame(1, $result['imported']);
        $activity = Activity::where('user_id', $this->user->id)->sole();
        $this->assertSame('whoop', $activity->source);
        $this->assertSame('Run', $activity->type);
        $this->assertSame(2400, (int) $activity->moving_time);
        $this->assertEquals(8000, $activity->distance);
        $this->assertSame(Carbon::parse('2026-11-13 10:00:00', 'UTC')->timestamp, (int) $activity->whoop_start);
        $this->assertSame('ecfc6a15-4661-442f-a9a4-f160dd7afae8', $activity->whoop_workout_id);

        // Zweiter Lauf: nichts doppelt.
        app(WhoopActivityImporter::class)->sync($this->user->fresh());
        $this->assertSame(1, Activity::where('user_id', $this->user->id)->count());
    }

    public function test_nothing_is_read_when_another_source_is_chosen(): void
    {
        $this->connect();
        $this->user->update(['activity_source' => 'polar']);
        PolarConnection::create(['user_id' => $this->user->id, 'polar_user_id' => 1, 'access_token' => 't', 'ai_consent_at' => now()]);

        $this->assertNull(app(WhoopActivityImporter::class)->sync($this->user->fresh()));
    }

    public function test_sport_names_map_to_zone3_types(): void
    {
        $this->assertSame('VirtualRide', WhoopActivityImporter::type(['sport_name' => 'spin']));
        $this->assertSame('Ride', WhoopActivityImporter::type(['sport_name' => 'mountain_biking']));
        $this->assertSame('WeightTraining', WhoopActivityImporter::type(['sport_name' => 'weightlifting']));
        $this->assertSame('Run', WhoopActivityImporter::type(['sport_id' => 0]));
        $this->assertSame('Workout', WhoopActivityImporter::type(['sport_name' => 'pickleball', 'sport_id' => 999]));
    }

    public function test_sleep_and_recovery_land_on_the_wake_day(): void
    {
        $this->connect();
        $sleepId = 'b2c3d4e5-0000-4000-8000-000000000001';
        Http::fake([
            self::API . '/v2/activity/sleep*' => Http::response(['records' => [
                // Nacht in Berlin, aufgewacht am 13.11. um 06:30 Ortszeit (05:30 UTC).
                ['id' => $sleepId, 'start' => '2026-11-12T22:00:00.000Z', 'end' => '2026-11-13T05:30:00.000Z', 'timezone_offset' => '+01:00',
                    'nap' => false, 'score_state' => 'SCORED', 'score' => ['respiratory_rate' => 15.24, 'stage_summary' => [
                        'total_light_sleep_time_milli' => 3 * 3_600_000, 'total_slow_wave_sleep_time_milli' => 2 * 3_600_000,
                        'total_rem_sleep_time_milli' => 1.5 * 3_600_000]]],
                // Nickerchen zählt nicht.
                ['id' => 'nap', 'start' => '2026-11-13T11:00:00.000Z', 'end' => '2026-11-13T11:30:00.000Z', 'timezone_offset' => '+01:00',
                    'nap' => true, 'score_state' => 'SCORED', 'score' => ['stage_summary' => ['total_light_sleep_time_milli' => 1_800_000]]],
            ]]),
            self::API . '/v2/recovery*' => Http::response(['records' => [
                ['sleep_id' => $sleepId, 'score_state' => 'SCORED', 'score' => [
                    'recovery_score' => 61, 'resting_heart_rate' => 48, 'hrv_rmssd_milli' => 62.7, 'spo2_percentage' => 96.4]],
            ]]),
        ]);

        app(WhoopHealthImporter::class)->sync($this->user->fresh());

        $metric = GarminDailyMetric::where('user_id', $this->user->id)->sole();
        $this->assertSame('2026-11-13', $metric->date->toDateString());
        $this->assertSame('whoop', $metric->source);
        $this->assertEquals(6.5, $metric->sleep_hours);
        $this->assertSame(63, (int) $metric->hrv);
        $this->assertSame(48, (int) $metric->resting_hr);
        $this->assertEquals(96.4, $metric->spo2);
        $this->assertEquals(15.2, $metric->respiration_rate);
    }

    public function test_a_watch_with_its_own_connection_wins_over_whoop(): void
    {
        $this->connect();
        PolarConnection::create(['user_id' => $this->user->id, 'polar_user_id' => 1, 'access_token' => 't', 'ai_consent_at' => now()]);

        $this->assertNull(app(WhoopHealthImporter::class)->sync($this->user->fresh()));
    }

    public function test_the_webhook_needs_a_valid_signature(): void
    {
        $this->connect();
        [$raw, $headers] = $this->signed(['user_id' => 10129, 'id' => 'ecfc6a15-4661-442f-a9a4-f160dd7afae8', 'type' => 'workout.updated', 'trace_id' => 't1'], 'wrong');

        $this->postWebhook([$raw], $headers)->assertStatus(401);
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('whoop_webhook_events', ['outcome' => 'bad_signature']);
    }

    public function test_an_old_timestamp_is_rejected(): void
    {
        $this->connect();
        [$raw, $headers] = $this->signed(['user_id' => 10129, 'id' => 'x', 'type' => 'sleep.updated', 'trace_id' => 't0'], 'secret', now()->subHour()->getTimestampMs());

        $this->postWebhook([$raw], $headers)->assertStatus(401);
    }

    public function test_a_signed_workout_webhook_queues_the_import_once(): void
    {
        $this->connect();
        [$raw, $headers] = $this->signed(['user_id' => 10129, 'id' => 'ecfc6a15-4661-442f-a9a4-f160dd7afae8', 'type' => 'workout.updated', 'trace_id' => 't2']);

        $this->postWebhook([$raw], $headers)->assertOk();
        $this->postWebhook([$raw], $headers)->assertOk();

        Queue::assertPushed(ImportWhoopWorkoutJob::class, 1);
        $this->assertDatabaseHas('whoop_webhook_events', ['outcome' => 'duplicate']);
    }

    public function test_a_sleep_webhook_queues_a_health_sync(): void
    {
        $this->connect();
        [$raw, $headers] = $this->signed(['user_id' => 10129, 'id' => 'b2c3d4e5-0000-4000-8000-000000000001', 'type' => 'recovery.updated', 'trace_id' => 't3']);

        $this->postWebhook([$raw], $headers)->assertOk();

        Queue::assertPushed(SyncWhoopJob::class, fn ($job) => $job->healthOnly);
    }

    public function test_a_workout_deleted_in_whoop_is_deleted_here_with_a_tombstone(): void
    {
        $this->connect();
        Http::fake([self::API . '/v2/activity/workout*' => Http::response(['records' => [$this->workout()]])]);
        app(WhoopActivityImporter::class)->sync($this->user->fresh());

        $outcome = app(WhoopActivityImporter::class)->deleteById($this->user->fresh(), 'ecfc6a15-4661-442f-a9a4-f160dd7afae8');

        $this->assertSame('deleted', $outcome);
        $this->assertSame(0, Activity::where('user_id', $this->user->id)->count());
        $this->assertTrue(IgnoredActivity::blocks($this->user->id, 'whoop', Carbon::parse('2026-11-13 10:00:00', 'UTC')->timestamp));
    }

    public function test_disconnecting_revokes_and_deletes_everything_from_whoop(): void
    {
        $this->connect();
        Http::fake([
            self::API . '/v2/activity/workout*' => Http::response(['records' => [$this->workout()]]),
            self::API . '/v2/user/access'       => Http::response('', 204),
        ]);
        app(WhoopActivityImporter::class)->sync($this->user->fresh());
        GarminDailyMetric::create(['user_id' => $this->user->id, 'date' => '2026-11-13', 'source' => 'whoop', 'hrv' => 60]);
        GarminDailyMetric::create(['user_id' => $this->user->id, 'date' => '2026-11-12', 'source' => 'apple', 'hrv' => 40]);

        $this->actingAs($this->user)->delete(route('whoop.connection.destroy'))->assertRedirect(route('profile.edit'));

        $this->assertDatabaseCount('whoop_connections', 0);
        $this->assertSame(0, Activity::where('user_id', $this->user->id)->count());
        $this->assertSame(['apple'], GarminDailyMetric::where('user_id', $this->user->id)->pluck('source')->all());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API . '/v2/user/access');
        // Ohne Grabstein: ein neues Verbinden bringt die Trainings wieder.
        $this->assertSame(0, IgnoredActivity::where('user_id', $this->user->id)->count());
    }
}
