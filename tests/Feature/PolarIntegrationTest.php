<?php

namespace Tests\Feature;

use App\Jobs\ImportPolarExerciseJob;
use App\Jobs\SyncPolarJob;
use App\Models\Activity;
use App\Models\AppSetting;
use App\Models\CorosConnection;
use App\Models\GarminDailyMetric;
use App\Models\IgnoredActivity;
use App\Models\PolarConnection;
use App\Models\User;
use App\Services\Polar\PolarActivityImporter;
use App\Services\Polar\PolarHealthImporter;
use App\Services\Polar\PolarWebhook;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Polar AccessLink. Die Antworten haben den Aufbau aus der Doku
 * (AccessLink v3, `polar-suunto/` der Projektdateien); ein echter Aufruf
 * stand beim Bau noch aus.
 */
class PolarIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-13 12:00:00';

    private const API = 'https://www.polaraccesslink.com';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        Queue::fake();
        config([
            'services.polar.client_id'     => 'cid',
            'services.polar.client_secret' => 'secret',
            'services.polar.redirect'      => 'https://zone3.run/polar/callback',
            'services.polar.api_url'       => self::API,
        ]);

        $this->user = User::factory()->onboarded()->create(['activity_source' => 'polar']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function connect(): PolarConnection
    {
        return PolarConnection::create([
            'user_id' => $this->user->id, 'polar_user_id' => 475, 'access_token' => 'tok', 'ai_consent_at' => now(),
        ]);
    }

    /** Lauf vor einer Stunde (Ortszeit Berlin = UTC+1, Polar schreibt den Versatz in Minuten). */
    private function summary(string $id = 'aQlC83'): array
    {
        return [
            'id'                    => $id,
            'device'                => 'Polar Pacer Pro',
            'start_time'            => '2026-11-13T12:00:00',
            'start_time_utc_offset' => 60,
            'duration'              => 'PT30M12.5S',
            'distance'              => 6000,
            'heart_rate'            => ['average' => 150, 'maximum' => 171],
            'sport'                 => 'RUNNING',
            'detailed_sport_info'   => 'RUNNING',
        ];
    }

    public function test_connecting_needs_consent(): void
    {
        $this->actingAs($this->user)->post(route('polar.connect'), [])->assertSessionHasErrors('ai_consent');
        $this->assertDatabaseCount('polar_connections', 0);
    }

    public function test_the_callback_stores_the_connection_and_registers_the_athlete(): void
    {
        Http::fake([
            'polarremote.com/*'    => Http::response(['access_token' => 'tok', 'token_type' => 'bearer', 'expires_in' => 31535999, 'x_user_id' => 475]),
            self::API . '/v3/users' => Http::response(['polar-user-id' => 475], 200),
        ]);
        Cache::put('polar-oauth:st', ['user_id' => $this->user->id], now()->addMinutes(10));

        $this->actingAs($this->user)->get('/polar/callback?state=st&code=abc')->assertRedirect();

        $connection = $this->user->fresh()->polarConnection;
        $this->assertSame(475, (int) $connection->polar_user_id);
        $this->assertNotNull($connection->ai_consent_at);
        $this->assertSame('polar', $this->user->fresh()->activity_source);
        Queue::assertPushed(SyncPolarJob::class);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'polarremote.com')
            && $r->header('Authorization')[0] === 'Basic ' . base64_encode('cid:secret'));
        Http::assertSent(fn (Request $r) => $r->url() === self::API . '/v3/users' && $r['member-id'] === 'zone3-' . $this->user->id);
    }

    public function test_an_already_registered_athlete_is_fine(): void
    {
        Http::fake([
            'polarremote.com/*'    => Http::response(['access_token' => 'tok', 'x_user_id' => 475]),
            self::API . '/v3/users' => Http::response('', 409),
        ]);
        Cache::put('polar-oauth:st', ['user_id' => $this->user->id], now()->addMinutes(10));

        $this->actingAs($this->user)->get('/polar/callback?state=st&code=abc');

        $this->assertNotNull($this->user->fresh()->polarConnection);
    }

    public function test_the_webhook_answers_the_ping_without_a_key(): void
    {
        $this->postJson('/polar/webhook', ['event' => 'PING', 'timestamp' => '2026-11-13T11:00:00Z'], ['Polar-Webhook-Event' => 'PING'])
            ->assertOk();
    }

    public function test_the_webhook_rejects_a_wrong_signature(): void
    {
        AppSetting::set('polar_webhook_secret', Crypt::encryptString('key'));
        $this->connect();

        $this->postJson('/polar/webhook', ['event' => 'EXERCISE', 'user_id' => 475, 'entity_id' => 'aQlC83'],
            ['Polar-Webhook-Event' => 'EXERCISE', 'Polar-Webhook-Signature' => 'falsch'])->assertStatus(401);

        Queue::assertNotPushed(ImportPolarExerciseJob::class);
    }

    public function test_a_signed_exercise_event_is_queued(): void
    {
        AppSetting::set('polar_webhook_secret', Crypt::encryptString('key'));
        $this->connect();
        $body = json_encode(['event' => 'EXERCISE', 'user_id' => 475, 'entity_id' => 'aQlC83', 'timestamp' => '2026-11-13T11:31:00Z',
            'url' => self::API . '/v3/exercises/aQlC83']);

        $this->call('POST', '/polar/webhook', [], [], [], [
            'CONTENT_TYPE'                 => 'application/json',
            'HTTP_POLAR_WEBHOOK_EVENT'     => 'EXERCISE',
            'HTTP_POLAR_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, 'key'),
        ], $body)->assertOk();

        Queue::assertPushed(ImportPolarExerciseJob::class, fn ($job) => $job->userId === $this->user->id && $job->exerciseId === 'aQlC83');
        $this->assertDatabaseHas('polar_webhook_events', ['event' => 'EXERCISE', 'outcome' => 'queued']);
    }

    public function test_an_exercise_is_imported_once_with_its_utc_start(): void
    {
        $this->connect();
        Http::fake([
            self::API . '/v3/exercises/aQlC83/fit' => Http::response('', 404),
            self::API . '/v3/exercises/aQlC83'     => Http::response($this->summary()),
        ]);

        $importer = app(PolarActivityImporter::class);
        $this->assertSame('imported', $importer->importById($this->user, 'aQlC83'));

        $activity = Activity::where('user_id', $this->user->id)->sole();
        $this->assertSame('polar', $activity->source);
        $this->assertSame('Run', $activity->type);
        $this->assertSame(1813, $activity->moving_time);
        $this->assertSame('2026-11-13 11:00:00', $activity->getRawOriginal('start_date') === null ? '' : Carbon::parse($activity->getRawOriginal('start_date'))->format('Y-m-d H:i:s'));
        $this->assertSame('Polar Pacer Pro', $activity->device_name);

        $this->assertSame('known', $importer->importById($this->user, 'aQlC83'));
        $this->assertSame(1, Activity::count());
    }

    public function test_nothing_is_read_when_polar_is_not_the_chosen_source(): void
    {
        $this->connect();
        $this->user->update(['activity_source' => 'strava']);
        $this->user->stravaAccount()->create(['strava_id' => 1, 'access_token' => 'a', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour()]);
        Http::fake();

        $this->assertNull(app(PolarActivityImporter::class)->sync($this->user->fresh()));
        Http::assertNothingSent();
    }

    public function test_the_list_skips_known_and_deleted_exercises(): void
    {
        $this->connect();
        $known   = $this->summary('known1');
        $deleted = array_merge($this->summary('gone1'), ['start_time' => '2026-11-12T08:00:00']);
        $new     = array_merge($this->summary('new1'), ['start_time' => '2026-11-11T18:00:00', 'sport' => 'CYCLING', 'detailed_sport_info' => 'INDOOR_CYCLING']);

        Activity::create(['user_id' => $this->user->id, 'source' => 'polar', 'polar_start' => PolarActivityImporter::startKey($known),
            'name' => 'Lauf', 'type' => 'Run', 'distance' => 5000, 'moving_time' => 1500, 'elapsed_time' => 1500, 'average_speed' => 3.3, 'start_date' => now()]);
        IgnoredActivity::create(['user_id' => $this->user->id, 'source' => 'polar', 'external_id' => PolarActivityImporter::startKey($deleted)]);

        Http::fake([
            self::API . '/v3/exercises/*/fit' => Http::response('', 404),
            self::API . '/v3/exercises'       => Http::response([$known, $deleted, $new]),
        ]);

        $result = app(PolarActivityImporter::class)->sync($this->user);

        $this->assertSame(1, $result['imported']);
        $this->assertSame('VirtualRide', Activity::where('polar_start', PolarActivityImporter::startKey($new))->value('type'));
        $this->assertSame(2, Activity::count());
    }

    public function test_health_lands_in_the_daily_metrics(): void
    {
        $this->connect();
        Http::fake([
            self::API . '/v3/users/sleep'             => Http::response(['nights' => [[
                'date' => '2026-11-13', 'light_sleep' => 14400, 'deep_sleep' => 5400, 'rem_sleep' => 7200, 'sleep_score' => 82,
            ]]]),
            self::API . '/v3/users/nightly-recharge'  => Http::response(['recharges' => [[
                'date' => '2026-11-13', 'heart_rate_avg' => 49, 'heart_rate_variability_avg' => 61, 'breathing_rate_avg' => 13.6,
            ]]]),
            self::API . '/v3/users/physical-info'     => Http::response(['vo2_max' => 55]),
        ]);

        app(PolarHealthImporter::class)->sync($this->user);

        $metric = GarminDailyMetric::where('user_id', $this->user->id)->whereDate('date', '2026-11-13')->sole();
        $this->assertSame('polar', $metric->source);
        $this->assertEquals(7.5, $metric->sleep_hours);
        $this->assertSame(82, (int) $metric->sleep_score);
        $this->assertSame(61, (int) $metric->hrv);
        $this->assertSame(49, (int) $metric->resting_hr);
        $this->assertEquals(55, $metric->vo2max);
        $this->assertTrue($this->user->fresh()->receivesWatchHealthMetrics());
    }

    public function test_another_watch_keeps_polar_health_out(): void
    {
        $this->connect();
        CorosConnection::create(['user_id' => $this->user->id, 'client_id' => 'c', 'access_token' => 't', 'refresh_token' => 'r',
            'access_token_expires_at' => now()->addDays(5), 'ai_consent_at' => now()]);
        Http::fake();

        $this->assertNull(app(PolarHealthImporter::class)->sync($this->user->fresh()));
        Http::assertNothingSent();
    }

    public function test_disconnecting_deregisters_and_deletes_everything_from_polar(): void
    {
        $this->connect();
        Activity::create(['user_id' => $this->user->id, 'source' => 'polar', 'polar_start' => 1, 'name' => 'Lauf', 'type' => 'Run', 'distance' => 5000, 'moving_time' => 1500, 'elapsed_time' => 1500, 'average_speed' => 3.3, 'start_date' => now()]);
        GarminDailyMetric::create(['user_id' => $this->user->id, 'date' => '2026-11-13', 'source' => 'polar', 'hrv' => 60]);
        Http::fake([self::API . '/v3/users/475' => Http::response('', 204)]);

        $this->actingAs($this->user)->delete(route('polar.connection.destroy'))->assertRedirect();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API . '/v3/users/475');
        $this->assertDatabaseCount('polar_connections', 0);
        $this->assertSame(0, Activity::count());
        $this->assertSame(0, GarminDailyMetric::count());
    }

    public function test_ensure_creates_the_webhook_and_keeps_its_key(): void
    {
        config(['app.url' => 'https://zone3.run']);
        Http::fake([
            self::API . '/v3/webhooks' => Http::sequence()
                ->push(['data' => []])
                ->push(['data' => ['id' => 'abdf33', 'events' => ['EXERCISE'], 'url' => 'https://zone3.run/polar/webhook', 'signature_secret_key' => 'k1']], 201),
        ]);

        $webhook = app(PolarWebhook::class);
        $this->assertSame('created', $webhook->ensure());
        $this->assertSame('k1', $webhook->secret());
    }

    public function test_iso_durations_and_start_keys(): void
    {
        $this->assertSame(9840, PolarActivityImporter::seconds('PT2H44M'));
        $this->assertSame(210, PolarActivityImporter::seconds('PT210.026S'));
        $this->assertSame(0, PolarActivityImporter::seconds(null));
        $this->assertSame(Carbon::parse('2026-11-13 11:00:00', 'UTC')->timestamp,
            PolarActivityImporter::startKey(['start_time' => '2026-11-13T12:00:00', 'start_time_utc_offset' => 60]));
    }
}
