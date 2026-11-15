<?php

namespace Tests\Feature;

use App\Jobs\ImportIntervalsActivityJob;
use App\Jobs\SyncIntervalsWorkoutsJob;
use App\Models\Activity;
use App\Models\Event;
use App\Models\IntervalsConnection;
use App\Models\IntervalsScheduledWorkout;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Bike\BikeWorkouts;
use App\Services\Intervals\IntervalsActivityImporter;
use App\Services\Intervals\IntervalsDisconnector;
use App\Services\Intervals\IntervalsWorkoutSync;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * intervals.icu als Brücke zu Zwift. Die Antworten haben den Aufbau aus der
 * API-Doku und dem Forum; ein echter Aufruf stand beim Bau noch aus.
 */
class IntervalsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 09:00:00';

    private const API = 'https://intervals.icu/api/v1';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        Queue::fake();
        config([
            'services.intervals.client_id'      => 'cid',
            'services.intervals.client_secret'  => 'secret',
            'services.intervals.webhook_secret' => 'hook-secret',
            'services.intervals.redirect'       => 'https://zone3.run/intervals/callback',
            'services.intervals.api_url'        => self::API,
        ]);

        $this->user = User::factory()->onboarded()->create(['activity_source' => 'garmin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function connect(): IntervalsConnection
    {
        return IntervalsConnection::create([
            'user_id' => $this->user->id, 'athlete_id' => 'i12345', 'access_token' => 'tok',
            'scopes' => 'CALENDAR:WRITE,ACTIVITY:READ', 'ai_consent_at' => now(),
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

    private function bikeSession(TrainingPlan $plan, string $date, bool $bike = true, array $extra = []): TrainingSession
    {
        return TrainingSession::withoutEvents(fn () => TrainingSession::create($extra + [
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
            'id' => 'i55751783', 'type' => 'VirtualRide', 'name' => 'Zwift - Watopia',
            'start_date' => '2026-11-16T07:00:00Z', 'start_date_local' => '2026-11-16T08:00:00',
            'distance' => 30000, 'moving_time' => 3600, 'elapsed_time' => 3650,
            'average_heartrate' => 132, 'icu_average_watts' => 180, 'icu_weighted_avg_watts' => 190,
            'device_name' => 'Zwift',
        ];
    }

    public function test_connecting_needs_consent(): void
    {
        $this->actingAs($this->user)->post(route('intervals.connect'), [])->assertSessionHasErrors('ai_consent');
    }

    public function test_connect_goes_to_intervals_with_pkce(): void
    {
        $response = $this->actingAs($this->user)->post(route('intervals.connect'), ['ai_consent' => true], ['X-Inertia' => 'true']);

        $location = $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://intervals.icu/oauth/authorize?', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
        $this->assertStringContainsString('scope=' . urlencode('CALENDAR:WRITE,ACTIVITY:READ'), $location);
    }

    public function test_the_callback_stores_the_connection_and_starts_the_sync(): void
    {
        Http::fake(['intervals.icu/api/oauth/token' => Http::response([
            'token_type' => 'Bearer', 'access_token' => 'tok', 'scope' => 'CALENDAR:WRITE,ACTIVITY:READ',
            'athlete' => ['id' => 'i12345', 'name' => 'Jan'],
        ])]);
        Cache::put('intervals-oauth:st', ['user_id' => $this->user->id, 'verifier' => 'ver'], now()->addMinutes(10));

        $this->actingAs($this->user)->get('/intervals/callback?state=st&code=abc')->assertRedirect();

        $connection = $this->user->fresh()->intervalsConnection;
        $this->assertSame('i12345', $connection->athlete_id);
        $this->assertSame('tok', $connection->access_token);
        $this->assertNotNull($connection->ai_consent_at);
        Queue::assertPushed(SyncIntervalsWorkoutsJob::class);
        Http::assertSent(fn (Request $r) => $r['code_verifier'] === 'ver' && $r['client_secret'] === 'secret');
    }

    public function test_bike_sessions_of_the_next_seven_days_go_to_the_calendar(): void
    {
        $this->connect();
        $plan  = $this->plan();
        $ride  = $this->bikeSession($plan, '2026-11-17');
        $this->bikeSession($plan, '2026-11-18', bike: false);   // Lauf: nicht
        $this->bikeSession($plan, '2026-11-25');                // hinter dem Fenster: nicht
        Http::fake([self::API . '/athlete/i12345/events/bulk*' => Http::response([['id' => 987, 'external_id' => 'zone3-session-' . $ride->id]])]);

        app(IntervalsWorkoutSync::class)->sync($this->user->fresh());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) use ($ride) {
            $event = $r->data()[0] ?? [];

            return str_contains($r->url(), 'upsert=true') && count($r->data()) === 1
                && $event['external_id'] === 'zone3-session-' . $ride->id
                && $event['category'] === 'WORKOUT' && $event['type'] === 'VirtualRide' && $event['indoor'] === true
                && $event['start_date_local'] === '2026-11-17T00:00:00'
                && str_contains($event['file_contents'], '<workout_file>') && str_ends_with($event['filename'], '.zwo');
        });
        $this->assertSame('987', (string) IntervalsScheduledWorkout::sole()->event_id);

        // Unverändert: kein zweiter Aufruf.
        app(IntervalsWorkoutSync::class)->sync($this->user->fresh());
        Http::assertSentCount(1);
    }

    public function test_a_session_that_left_the_plan_is_removed_from_the_calendar(): void
    {
        $this->connect();
        $ride = $this->bikeSession($this->plan(), '2026-11-17');
        Http::fake([self::API . '/athlete/i12345/events/*' => Http::response([])]);
        app(IntervalsWorkoutSync::class)->sync($this->user->fresh());

        TrainingSession::withoutEvents(fn () => $ride->delete());
        app(IntervalsWorkoutSync::class)->sync($this->user->fresh());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'events/bulk-delete')
            && $r->method() === 'PUT' && $r->data() === [['external_id' => 'zone3-session-' . $ride->id]]);
        $this->assertSame(0, IntervalsScheduledWorkout::count());
    }

    public function test_the_webhook_rejects_a_wrong_secret(): void
    {
        $this->postJson('/intervals/webhook', ['secret' => 'nope', 'events' => []])->assertStatus(401);
        $this->postJson('/intervals/webhook', ['events' => []], ['Authorization' => 'nope'])->assertStatus(401);
    }

    public function test_the_webhook_accepts_the_secret_in_body_or_header_and_queues_the_ride(): void
    {
        $this->connect();
        $event = ['athlete_id' => 'i12345', 'type' => 'ACTIVITY_UPLOADED', 'activity' => $this->ride()];

        $this->postJson('/intervals/webhook', ['secret' => 'hook-secret', 'events' => [$event]])->assertOk();
        $this->postJson('/intervals/webhook', ['events' => [$event]], ['Authorization' => 'Bearer hook-secret'])->assertOk();

        Queue::assertPushed(ImportIntervalsActivityJob::class, 2);
    }

    public function test_an_unknown_athlete_is_only_recorded(): void
    {
        $this->postJson('/intervals/webhook', ['secret' => 'hook-secret', 'events' => [
            ['athlete_id' => 'i999', 'type' => 'ACTIVITY_UPLOADED', 'activity' => $this->ride()],
        ]])->assertOk();

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('intervals_webhook_events', ['outcome' => 'unknown_user']);
    }

    public function test_a_zwift_ride_completes_the_bike_session(): void
    {
        $this->connect();
        $session = $this->bikeSession($this->plan(), '2026-11-16');
        Http::fake([
            self::API . '/activity/i55751783/file' => Http::response('', 404),
            self::API . '/activity/i55751783'      => Http::response($this->ride()),
            '*'                                     => Http::response([]),
        ]);

        $result = app(IntervalsActivityImporter::class)->importById($this->user->fresh(), 'i55751783');

        $this->assertSame('imported', $result);
        $activity = Activity::where('user_id', $this->user->id)->sole();
        $this->assertSame('intervals', $activity->source);
        $this->assertSame('VirtualRide', $activity->type);
        $this->assertSame('i55751783', $activity->intervals_activity_id);
        $this->assertSame(Carbon::parse('2026-11-16 07:00:00', 'UTC')->timestamp, (int) $activity->intervals_start);
        $this->assertSame('completed', $session->fresh()->status);
        $this->assertSame($activity->id, $session->fresh()->activity_id);

        // Zweiter Webhook für dieselbe Fahrt: nichts doppelt.
        $this->assertSame('known', app(IntervalsActivityImporter::class)->importById($this->user->fresh(), 'i55751783'));
        $this->assertSame(1, Activity::count());
    }

    public function test_only_zwift_rides_are_taken(): void
    {
        $this->assertTrue(IntervalsActivityImporter::isZwift(['type' => 'VirtualRide']));
        $this->assertTrue(IntervalsActivityImporter::isZwift(['type' => 'Ride', 'device_name' => 'Zwift']));
        $this->assertTrue(IntervalsActivityImporter::isZwift(['type' => 'Ride', 'device_name' => 'ROUVY']));
        $this->assertTrue(IntervalsActivityImporter::isZwift(['type' => 'Ride', 'name' => 'MyWhoosh - Tempus Fugit']));
        $this->assertFalse(IntervalsActivityImporter::isZwift(['type' => 'Run', 'device_name' => 'Garmin Enduro 3']));
        $this->assertFalse(IntervalsActivityImporter::isZwift(['type' => 'Ride', 'device_name' => 'Garmin Edge']));
        $this->assertFalse(IntervalsActivityImporter::isZwift(['type' => 'VirtualRide', 'source' => 'STRAVA']));

        $this->connect();
        Http::fake([self::API . '/activity/i1' => Http::response($this->ride(['id' => 'i1', 'type' => 'Run', 'name' => 'Morgenlauf', 'device_name' => 'Garmin']))]);
        $this->assertSame('not_zwift', app(IntervalsActivityImporter::class)->importById($this->user->fresh(), 'i1'));
        $this->assertSame(0, Activity::count());
    }

    public function test_a_deleted_ride_goes_and_stays_gone(): void
    {
        $this->connect();
        Http::fake([
            self::API . '/activity/i55751783/file' => Http::response('', 404),
            self::API . '/activity/i55751783'      => Http::response($this->ride()),
            '*'                                     => Http::response([]),
        ]);
        $importer = app(IntervalsActivityImporter::class);
        $importer->importById($this->user->fresh(), 'i55751783');

        $this->assertSame('deleted', $importer->deleteById($this->user->fresh(), 'i55751783'));
        $this->assertSame(0, Activity::count());
        $this->assertSame('tombstoned', $importer->importById($this->user->fresh(), 'i55751783'));
    }

    public function test_a_ride_another_source_brought_is_only_detached(): void
    {
        $this->connect();
        $activity = Activity::create([
            'user_id' => $this->user->id, 'source' => 'garmin', 'garmin_activity_id' => 77, 'name' => 'Zwift',
            'type' => 'VirtualRide', 'start_date' => '2026-11-16 07:00:00', 'distance' => 30000, 'moving_time' => 3600,
            'elapsed_time' => 3600, 'intervals_start' => 1, 'intervals_activity_id' => 'i5',
        ]);

        $this->assertSame('detached', app(IntervalsActivityImporter::class)->deleteById($this->user->fresh(), 'i5'));
        $this->assertNull($activity->fresh()->intervals_activity_id);
        $this->assertNotNull($activity->fresh());
    }

    public function test_disconnecting_clears_the_calendar_and_revokes(): void
    {
        $this->connect();
        $this->bikeSession($this->plan(), '2026-11-17');
        Http::fake([self::API . '/*' => Http::response([])]);
        app(IntervalsWorkoutSync::class)->sync($this->user->fresh());

        app(IntervalsDisconnector::class)->disconnect($this->user->fresh());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'events/bulk-delete'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'disconnect-app') && $r->method() === 'DELETE');
        $this->assertNull($this->user->fresh()->intervalsConnection);
        $this->assertSame(0, IntervalsScheduledWorkout::count());
    }
}
