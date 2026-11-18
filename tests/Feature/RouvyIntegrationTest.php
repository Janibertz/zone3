<?php

namespace Tests\Feature;

use App\Jobs\ImportRouvyActivityJob;
use App\Jobs\SyncRouvyActivitiesJob;
use App\Jobs\SyncRouvyWorkoutsJob;
use App\Models\Activity;
use App\Models\Event;
use App\Models\RouvyConnection;
use App\Models\RouvyScheduledWorkout;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Bike\BikeWorkouts;
use App\Services\Rouvy\RouvyActivityImporter;
use App\Services\Rouvy\RouvyDisconnector;
use App\Services\Rouvy\RouvyWorkoutSync;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ROUVY Public API. Die Antworten haben den Aufbau aus der OpenAPI-Datei
 * (rouvy-antrag/ in den Projektdateien); ein echter Aufruf stand beim Bau aus.
 */
class RouvyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 09:00:00';

    private const API = 'https://api.rouvy.com';

    private User $user;

    private string $secretKey;

    private string $publicWhpk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        Queue::fake();
        config([
            'services.rouvy.client_id'     => 'cid',
            'services.rouvy.client_secret' => 'secret',
            'services.rouvy.redirect'      => 'https://zone3.run/rouvy/callback',
            'services.rouvy.api_url'       => self::API,
        ]);

        $pair             = sodium_crypto_sign_keypair();
        $this->secretKey  = sodium_crypto_sign_secretkey($pair);
        $this->publicWhpk = 'whpk_' . base64_encode(hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($pair));

        $this->user = User::factory()->onboarded()->create(['activity_source' => 'garmin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function connect(array $extra = []): RouvyConnection
    {
        return RouvyConnection::create($extra + [
            'user_id' => $this->user->id, 'rouvy_user_id' => 'abc123', 'access_token' => 'tok',
            'refresh_token' => 'ref', 'expires_at' => now()->addHour(), 'ai_consent_at' => now(),
        ]);
    }

    private function plan(): TrainingPlan
    {
        $event = Event::create([
            'user_id' => $this->user->id, 'name' => 'HM', 'event_date' => '2027-03-14',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);

        return TrainingPlan::create(['user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);
    }

    private function bikeSession(TrainingPlan $plan, string $date, bool $bike = true): TrainingSession
    {
        return TrainingSession::withoutEvents(fn () => TrainingSession::create([
            'user_id' => $this->user->id, 'training_plan_id' => $plan->id, 'event_id' => $plan->event_id,
            'planned_date' => $date, 'status' => 'planned',
        ] + ($bike ? [
            'type' => 'cross_training', 'sport_type' => 'VirtualRide', 'title' => 'Rad locker', 'description' => 'Locker',
            'intensity' => 'low', 'duration_min' => 60,
            'steps' => app(BikeWorkouts::class)->build(BikeWorkouts::EASY, 60, 230)['steps'],
        ] : [
            'type' => 'easy_run', 'title' => 'Locker', 'description' => 'Locker', 'intensity' => 'low', 'duration_min' => 45,
        ])));
    }

    private function ride(array $extra = []): array
    {
        return $extra + [
            'activityId' => 'r123abc', 'userId' => 'abc123', 'activityType' => 'activity',
            'activityTitle' => 'Passo Stelvio', 'startDateUTC' => '2026-11-16T07:00:00Z', 'hourOffset' => 1,
            'elapsedTimeSeconds' => 3650, 'movingTimeSeconds' => 3600, 'distanceMeters' => 30000,
            'elevationGainMeters' => 800, 'normalizedPowerWatts' => 190,
            'aggregates' => ['powerWatts' => ['avg' => 180, 'max' => 400], 'heartRateBPM' => ['avg' => 132, 'max' => 160]],
        ];
    }

    /** @return array{0:string, 1:array} Body und Header, signiert wie ROUVY. */
    private function signed(array $body, ?int $time = null, ?string $id = 'msg_1'): array
    {
        $raw  = json_encode($body);
        $time ??= time();
        $sig  = base64_encode(sodium_crypto_sign_detached("{$id}.{$time}.{$raw}", $this->secretKey));

        return [$raw, [
            'webhook-id' => $id, 'webhook-timestamp' => (string) $time, 'webhook-signature' => 'v1a,' . $sig,
            'Content-Type' => 'application/json',
        ]];
    }

    private function fakeKeys(): void
    {
        Http::fake([self::API . '/webhooks/public-keys' => Http::response(['keys' => [['kid' => 'k1', 'alg' => 'ed25519', 'key' => $this->publicWhpk]]])]);
    }

    private function postWebhook(array $body, ?int $time = null, ?string $id = 'msg_1')
    {
        [$raw, $headers] = $this->signed($body, $time, $id);
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        return $this->call('POST', '/rouvy/webhook', [], [], [], $server, $raw);
    }

    public function test_connecting_needs_consent(): void
    {
        $this->actingAs($this->user)->post(route('rouvy.connect'), [])->assertSessionHasErrors('ai_consent');
    }

    public function test_connect_goes_to_rouvy(): void
    {
        $response = $this->actingAs($this->user)->post(route('rouvy.connect'), ['ai_consent' => true], ['X-Inertia' => 'true']);

        $location = $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith(self::API . '/oauth/authorize?', $location);
        $this->assertStringContainsString('scope=' . urlencode('profile:read activities:read workouts:write'), $location);
        $this->assertStringNotContainsString('code_challenge', $location);
    }

    public function test_the_callback_stores_the_connection_and_starts_both_syncs(): void
    {
        Http::fake([
            self::API . '/oauth/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            self::API . '/me'          => Http::response(['user' => ['userId' => 'abc123', 'email' => 'x@y.z']]),
        ]);
        Cache::put('rouvy-oauth:st', ['user_id' => $this->user->id], now()->addMinutes(10));

        $this->actingAs($this->user)->get('/rouvy/callback?response=success&state=st&code=abc')->assertRedirect();

        $connection = $this->user->fresh()->rouvyConnection;
        $this->assertSame('abc123', $connection->rouvy_user_id);
        $this->assertSame('ref', $connection->refresh_token);
        $this->assertNotNull($connection->ai_consent_at);
        Queue::assertPushed(SyncRouvyWorkoutsJob::class);
        Queue::assertPushed(SyncRouvyActivitiesJob::class);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/token') && $r['client_secret'] === 'secret' && $r['grant_type'] === 'authorization_code');
    }

    public function test_an_error_callback_stores_nothing(): void
    {
        Cache::put('rouvy-oauth:st', ['user_id' => $this->user->id], now()->addMinutes(10));

        $this->actingAs($this->user)->get('/rouvy/callback?response=error&reason=access_denied&state=st')->assertRedirect();

        $this->assertNull($this->user->fresh()->rouvyConnection);
    }

    public function test_bike_sessions_of_the_next_seven_days_are_pushed_once(): void
    {
        $this->connect();
        $plan = $this->plan();
        $ride = $this->bikeSession($plan, '2026-11-17');
        $this->bikeSession($plan, '2026-11-18', bike: false);   // Lauf: nicht
        $this->bikeSession($plan, '2026-11-25');                // hinter dem Fenster: nicht
        Http::fake([self::API . '/workouts/push' => Http::response(['status' => 'ok', 'workoutId' => 4711], 201)]);

        app(RouvyWorkoutSync::class)->sync($this->user->fresh());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/workouts/push') && $r->isMultipart()
            && collect($r->data())->contains(fn ($part) => $part['name'] === 'plannedAt' && $part['contents'] === '2026-11-17T12:00:00Z')
            && collect($r->data())->contains(fn ($part) => $part['name'] === 'file' && str_contains($part['contents'], '<workout_file>')));
        $row = RouvyScheduledWorkout::sole();
        $this->assertSame('4711', $row->workout_id);
        $this->assertSame($ride->id, $row->training_session_id);

        // Unverändert: kein zweiter Aufruf.
        app(RouvyWorkoutSync::class)->sync($this->user->fresh());
        Http::assertSentCount(1);
    }

    public function test_a_changed_session_is_deleted_and_pushed_again(): void
    {
        $this->connect();
        $ride = $this->bikeSession($this->plan(), '2026-11-17');
        Http::fakeSequence(self::API . '/workouts/push')
            ->push(['status' => 'ok', 'workoutId' => 1], 201)
            ->push(['status' => 'ok', 'workoutId' => 2], 201);
        Http::fake([self::API . '/workouts/1' => Http::response('', 204)]);
        app(RouvyWorkoutSync::class)->sync($this->user->fresh());

        TrainingSession::withoutEvents(fn () => $ride->update(['title' => 'Rad länger']));
        app(RouvyWorkoutSync::class)->sync($this->user->fresh());

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/workouts/1'));
        $this->assertSame('2', RouvyScheduledWorkout::sole()->workout_id);
    }

    public function test_a_session_that_left_the_plan_is_removed(): void
    {
        $this->connect();
        $ride = $this->bikeSession($this->plan(), '2026-11-17');
        Http::fake([
            self::API . '/workouts/push' => Http::response(['status' => 'ok', 'workoutId' => 9], 201),
            self::API . '/workouts/9'    => Http::response('', 204),
        ]);
        app(RouvyWorkoutSync::class)->sync($this->user->fresh());

        TrainingSession::withoutEvents(fn () => $ride->delete());
        $result = app(RouvyWorkoutSync::class)->sync($this->user->fresh());

        $this->assertSame(1, $result['removed']);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/workouts/9'));
        $this->assertSame(0, RouvyScheduledWorkout::count());
    }

    public function test_a_missing_username_is_explained(): void
    {
        $connection = $this->connect();
        $this->bikeSession($this->plan(), '2026-11-17');
        Http::fake([self::API . '/workouts/push' => Http::response(['error' => 'incomplete_user_profile'], 409)]);

        app(RouvyWorkoutSync::class)->sync($this->user->fresh());

        $this->assertStringContainsString('Benutzername', $connection->fresh()->last_error);
        $this->assertSame(0, RouvyScheduledWorkout::count());
    }

    public function test_an_expired_token_is_refreshed_and_the_new_refresh_token_kept(): void
    {
        $this->connect(['expires_at' => now()->subMinute()]);
        $this->bikeSession($this->plan(), '2026-11-17');
        Http::fake([
            self::API . '/oauth/token'   => Http::response(['access_token' => 'tok2', 'refresh_token' => 'ref2', 'expires_in' => 3600]),
            self::API . '/workouts/push' => Http::response(['status' => 'ok', 'workoutId' => 3], 201),
        ]);

        app(RouvyWorkoutSync::class)->sync($this->user->fresh());

        $connection = $this->user->fresh()->rouvyConnection;
        $this->assertSame('tok2', $connection->access_token);
        $this->assertSame('ref2', $connection->refresh_token);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/workouts/push') && $r->hasHeader('Authorization', 'Bearer tok2'));
    }

    public function test_the_webhook_rejects_a_bad_signature(): void
    {
        $this->fakeKeys();
        $this->connect();
        [$raw] = $this->signed(['type' => 'activity', 'clientId' => 'cid', 'ownerId' => 'abc123', 'payload' => $this->ride()]);

        $this->call('POST', '/rouvy/webhook', [], [], [], [
            'HTTP_WEBHOOK_ID' => 'msg_1', 'HTTP_WEBHOOK_TIMESTAMP' => (string) time(),
            'HTTP_WEBHOOK_SIGNATURE' => 'v1a,' . base64_encode(str_repeat("\0", 64)), 'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_the_webhook_rejects_an_old_timestamp(): void
    {
        $this->fakeKeys();
        $this->connect();

        $this->postWebhook(['type' => 'activity', 'clientId' => 'cid', 'ownerId' => 'abc123', 'payload' => $this->ride()], time() - 600)
            ->assertStatus(401);
    }

    public function test_a_signed_activity_is_queued_once(): void
    {
        $this->fakeKeys();
        $this->connect();
        $body = ['type' => 'activity', 'clientId' => 'cid', 'ownerId' => 'abc123', 'payload' => $this->ride()];

        $this->postWebhook($body)->assertOk();
        $this->postWebhook($body)->assertOk();   // Wiederholung mit derselben webhook-id

        Queue::assertPushed(ImportRouvyActivityJob::class, 1);
        $this->assertDatabaseHas('rouvy_webhook_events', ['webhook_id' => 'msg_1', 'outcome' => 'queued', 'entity_id' => 'r123abc']);
    }

    public function test_a_webhook_for_another_client_is_ignored(): void
    {
        $this->fakeKeys();
        $this->connect();

        $this->postWebhook(['type' => 'activity', 'clientId' => 'someone-else', 'ownerId' => 'abc123', 'payload' => $this->ride()])->assertOk();

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('rouvy_webhook_events', ['outcome' => 'other_client']);
    }

    public function test_a_disconnect_webhook_removes_the_connection(): void
    {
        $this->fakeKeys();
        $this->connect();

        $this->postWebhook(['type' => 'disconnect', 'clientId' => 'cid', 'ownerId' => 'abc123', 'payload' => []])->assertOk();

        $this->assertNull($this->user->fresh()->rouvyConnection);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/oauth/revoke'));
    }

    public function test_a_rouvy_ride_completes_the_bike_session(): void
    {
        $this->connect();
        $session = $this->bikeSession($this->plan(), '2026-11-16');
        Http::fake([
            self::API . '/activities/r123abc/fit' => Http::response('', 404),
            '*'                                    => Http::response([]),
        ]);

        $result = app(RouvyActivityImporter::class)->importPayload($this->user->fresh(), $this->ride());

        $this->assertSame('imported', $result);
        $activity = Activity::where('user_id', $this->user->id)->sole();
        $this->assertSame('rouvy', $activity->source);
        $this->assertSame('VirtualRide', $activity->type);
        $this->assertSame('r123abc', $activity->rouvy_activity_id);
        $this->assertSame(180, (int) $activity->average_watts);
        $this->assertSame(190, (int) $activity->weighted_average_watts);
        $this->assertSame(Carbon::parse('2026-11-16 07:00:00', 'UTC')->timestamp, (int) $activity->rouvy_start);
        $this->assertSame('completed', $session->fresh()->status);

        // Dieselbe Fahrt aus dem Netz: nichts doppelt.
        $this->assertSame('known', app(RouvyActivityImporter::class)->importPayload($this->user->fresh(), $this->ride()));
        $this->assertSame(1, Activity::count());
    }

    public function test_without_consent_nothing_is_read(): void
    {
        $this->connect(['ai_consent_at' => null]);

        $this->assertSame('no_consent', app(RouvyActivityImporter::class)->importPayload($this->user->fresh(), $this->ride()));
        $this->assertSame(0, Activity::count());
    }

    public function test_the_sync_reaches_back_six_weeks_the_first_time(): void
    {
        $this->connect();
        Http::fake([self::API . '/activities*' => Http::response(['activities' => []])]);

        app(RouvyActivityImporter::class)->sync($this->user->fresh());
        app(RouvyActivityImporter::class)->sync($this->user->fresh());

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]['fromDateTime'])->values();
        $this->assertSame(now()->subDays(42)->utc()->format('Y-m-d\TH:i:s\Z'), $sent[0]);
        $this->assertSame(now()->subDays(3)->utc()->format('Y-m-d\TH:i:s\Z'), $sent[1]);
    }

    public function test_disconnecting_removes_upcoming_workouts_and_revokes(): void
    {
        $this->connect();
        $this->bikeSession($this->plan(), '2026-11-17');
        Http::fake([
            self::API . '/workouts/push' => Http::response(['status' => 'ok', 'workoutId' => 5], 201),
            self::API . '/*'             => Http::response([], 200),
        ]);
        app(RouvyWorkoutSync::class)->sync($this->user->fresh());

        app(RouvyDisconnector::class)->disconnect($this->user->fresh());

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/workouts/5'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/revoke') && $r['token'] === 'ref');
        $this->assertNull($this->user->fresh()->rouvyConnection);
        $this->assertSame(0, RouvyScheduledWorkout::count());
    }

    public function test_rouvy_stays_hidden_without_credentials(): void
    {
        config(['services.rouvy.client_id' => null]);

        $this->actingAs($this->user)->post(route('rouvy.connect'), ['ai_consent' => true])->assertNotFound();
    }
}
