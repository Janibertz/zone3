<?php

namespace Tests\Feature;

use App\Jobs\GenerateSessionReviewJob;
use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\GarminConnection;
use App\Models\GarminWebhookEvent;
use App\Models\IgnoredActivity;
use App\Models\User;
use App\Services\BestEffortService;
use App\Services\Garmin\GarminActivityType;
use App\Services\Polyline;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Aktivitäten per Push über die offizielle Activity API.
 *
 * Feldnamen nach der Activity API Specification 2.0. Die Beispiele hier
 * folgen ihren Beispielen — wer einen Namen ändert, prüft gegen das PDF.
 */
class GarminActivityWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const START = 1793170800; // 2026-10-28 07:00:00 UTC

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 18:00:00');

        // Review-Jobs reden mit OpenAI — nie im Test.
        Queue::fake([GenerateSessionReviewJob::class]);

        $this->user = User::factory()->onboarded()->create();

        GarminConnection::create([
            'user_id'                 => $this->user->id,
            'garmin_user_id'          => 'g-user',
            'access_token'            => 'a',
            'refresh_token'           => 'r',
            'access_token_expires_at' => now()->addHours(20),
            'permissions'             => [GarminConnection::ACTIVITY, GarminConnection::HEALTH],
            'ai_consent_at'           => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function summary(array $overrides = []): array
    {
        return array_merge([
            'userId'                           => 'g-user',
            'summaryId'                        => '5001968355',
            'activityId'                       => 5001968355,
            'activityType'                     => 'RUNNING',
            'activityName'                     => 'Hannover Laufen',
            'startTimeInSeconds'               => self::START,
            'startTimeOffsetInSeconds'         => 3600,
            'durationInSeconds'                => 3000,
            'distanceInMeters'                 => 10000.0,
            'averageSpeedInMetersPerSecond'    => 3.333,
            'maxSpeedInMetersPerSecond'        => 4.2,
            'averageHeartRateInBeatsPerMinute' => 148,
            'maxHeartRateInBeatsPerMinute'     => 171.0,
            'totalElevationGainInMeters'       => 42.0,
            'startingLatitudeInDegree'         => 52.37,
            'startingLongitudeInDegree'        => 9.73,
            'deviceName'                       => 'Forerunner 965',
        ], $overrides);
    }

    /**
     * Ein gleichmässiger Lauf: 3000 s, 10 km, ein Punkt alle 10 s, Runden je
     * 1000 s. Von 1000 bis 1500 s schneller (4 m/s), damit die 1-km-Bestzeit
     * nicht einfach der Durchschnitt ist.
     */
    private function details(array $summaryOverrides = []): array
    {
        $samples  = [];
        $distance = 0.0;
        for ($t = 0; $t <= 3000; $t += 10) {
            $samples[] = [
                'startTimeInSeconds'      => self::START + $t,
                'latitudeInDegree'        => 52.37 + $t / 100000,
                'longitudeInDegree'       => 9.73 + $t / 100000,
                'heartRate'               => 140 + intdiv($t, 1000) * 5,
                'speedMetersPerSecond'    => 3.333,
                'totalDistanceInMeters'   => $distance,
                'timerDurationInSeconds'  => $t,
                'clockDurationInSeconds'  => $t,
                'movingDurationInSeconds' => max(0, $t - 30),
            ];
            // 1000–1500 s: 4 m/s, sonst 3,2 m/s — zusammen genau 10 km.
            $distance += ($t >= 1000 && $t < 1500) ? 40.0 : 32.0;
        }

        return [
            'userId'     => 'g-user',
            'summaryId'  => '5001968355-detail',
            'activityId' => 5001968355,
            'summary'    => array_diff_key($this->summary($summaryOverrides), array_flip(['userId', 'summaryId', 'activityId'])),
            'samples'    => $samples,
            'laps'       => [
                ['startTimeInSeconds' => self::START],
                ['startTimeInSeconds' => self::START + 1000],
                ['startTimeInSeconds' => self::START + 2000],
            ],
        ];
    }

    private function push(string $type, array $item): void
    {
        $this->postJson('/garmin/webhook/activities', [$type => [$item]])->assertOk();
    }

    private function chooseGarmin(): void
    {
        $this->user->forceFill(['activity_source' => Activity::SOURCE_GARMIN])->save();
    }

    // ── Anlegen ──────────────────────────────────────────────────────────

    public function test_a_summary_becomes_an_activity(): void
    {
        $this->chooseGarmin();

        $this->push('activities', $this->summary());

        $activity = Activity::sole();
        $this->assertSame(Activity::SOURCE_GARMIN, $activity->source);
        $this->assertSame(5001968355, (int) $activity->garmin_activity_id);
        $this->assertNull($activity->strava_id);
        $this->assertSame('Run', $activity->type);
        $this->assertSame('Hannover Laufen', $activity->name);
        $this->assertEquals(10000, $activity->distance);
        $this->assertSame('Forerunner 965', $activity->device_name);
        // Dieselbe Konvention wie Stravas `start_date` (…Z): die Spalte hält
        // die UTC-Uhrzeit. Nur so findet der Abgleich dieselbe Einheit.
        $this->assertStringStartsWith('2026-10-28 07:00:00', $activity->getRawOriginal('start_date'));
        $this->assertSame(GarminWebhookEvent::OUTCOME_IMPORTED, GarminWebhookEvent::sole()->outcome);
    }

    public function test_details_bring_route_laps_moving_time_and_best_efforts(): void
    {
        $this->chooseGarmin();

        $this->push('activityDetails', $this->details());

        $activity = Activity::sole();
        $this->assertNotEmpty($activity->polyline['polyline']);
        $this->assertCount(3, $activity->laps);
        $this->assertSame(1000, $activity->laps[0]['elapsed_time']);
        $this->assertSame(2970, $activity->moving_time);

        $efforts = BestEffort::where('activity_id', $activity->id)->pluck('elapsed_time', 'distance_key');
        $this->assertEqualsCanonicalizing(['1k', '5k', '10k'], $efforts->keys()->all());
        $this->assertSame(250, $efforts['1k']);      // die schnellen 1000 m
        $this->assertSame(3000, $efforts['10k']);
        $this->assertNotNull($activity->best_efforts_synced_at);
    }

    public function test_details_after_the_summary_complete_the_same_row(): void
    {
        $this->chooseGarmin();

        $this->push('activities', $this->summary());
        $this->push('activityDetails', $this->details());

        $activity = Activity::sole();
        $this->assertCount(3, $activity->laps);
        $this->assertTrue(BestEffort::where('activity_id', $activity->id)->exists());
        $this->assertSame(GarminWebhookEvent::OUTCOME_UPDATED, GarminWebhookEvent::latest('id')->first()->outcome);
    }

    public function test_the_second_push_creates_no_second_session(): void
    {
        $this->chooseGarmin();

        $this->push('activities', $this->summary());
        $sessions = $this->user->trainingSessions()->count();

        $this->push('activityDetails', $this->details());

        $this->assertSame($sessions, $this->user->trainingSessions()->count());
    }

    public function test_a_manual_update_without_activity_id_finds_the_row(): void
    {
        $this->chooseGarmin();
        $this->push('activities', $this->summary());

        $update = $this->summary(['activityName' => 'Abendlauf', 'deviceName' => 'unknown']);
        unset($update['activityId']);
        $this->push('manuallyUpdatedActivities', $update);

        $activity = Activity::sole();
        $this->assertSame('Abendlauf', $activity->name);
    }

    public function test_the_same_run_from_strava_gets_the_garmin_id_attached(): void
    {
        $this->chooseGarmin();
        $strava = Activity::create([
            'user_id' => $this->user->id, 'strava_id' => 998877, 'source' => Activity::SOURCE_STRAVA,
            'name' => 'Morgenlauf', 'type' => 'Run', 'distance' => 10080, 'moving_time' => 2990,
            'elapsed_time' => 3010, 'average_speed' => 3.37, 'start_date' => '2026-10-28T07:00:30Z',
        ]);

        $this->push('activityDetails', $this->details());

        $activity = Activity::sole();
        $this->assertSame($strava->id, $activity->id);
        $this->assertSame(5001968355, (int) $activity->garmin_activity_id);
        $this->assertEquals(10080, $activity->distance);   // Stravas Zahlen bleiben
        $this->assertCount(3, $activity->laps);             // Lücken füllt Garmin
        $this->assertSame(GarminWebhookEvent::OUTCOME_ATTACHED, GarminWebhookEvent::sole()->outcome);
        Queue::assertNotPushed(GenerateSessionReviewJob::class);
    }

    public function test_a_multisport_parent_is_not_stored(): void
    {
        $this->chooseGarmin();

        $this->push('activities', $this->summary(['activityType' => 'MULTI_SPORT', 'isParent' => true]));

        $this->assertSame(0, Activity::count());
    }

    // ── Tor und Grabstein ────────────────────────────────────────────────

    public function test_a_strava_athlete_gets_nothing_from_garmin(): void
    {
        \App\Models\StravaAccount::create([
            'user_id' => $this->user->id, 'strava_id' => 4711,
            'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addDay(),
        ]);

        $this->push('activities', $this->summary());

        $this->assertSame(0, Activity::count());
        $this->assertSame(GarminWebhookEvent::OUTCOME_OTHER_SOURCE, GarminWebhookEvent::sole()->outcome);
    }

    public function test_without_activity_permission_nothing_is_stored(): void
    {
        $this->user->garminConnection->update(['permissions' => [GarminConnection::HEALTH]]);

        $this->push('activities', $this->summary());

        $this->assertSame(0, Activity::count());
        $this->assertSame(GarminWebhookEvent::OUTCOME_NOT_PERMITTED, GarminWebhookEvent::sole()->outcome);
    }

    public function test_a_deleted_activity_stays_deleted(): void
    {
        $this->chooseGarmin();
        IgnoredActivity::create(['user_id' => $this->user->id, 'source' => 'garmin', 'external_id' => 5001968355]);

        $this->push('activities', $this->summary());

        $this->assertSame(0, Activity::count());
    }

    public function test_an_unknown_user_is_logged_and_ignored(): void
    {
        $this->push('activities', $this->summary(['userId' => 'fremd']));

        $this->assertSame(0, Activity::count());
        $this->assertSame(GarminWebhookEvent::OUTCOME_UNKNOWN_USER, GarminWebhookEvent::sole()->outcome);
    }

    public function test_the_logged_payload_keeps_only_a_few_samples(): void
    {
        $this->chooseGarmin();

        $this->push('activityDetails', $this->details());

        $logged = json_decode(GarminWebhookEvent::sole()->payload, true);
        $this->assertCount(3, $logged['samples']);
        $this->assertSame(301, $logged['samples_count']);
    }

    // ── Bausteine ────────────────────────────────────────────────────────

    public function test_garmin_types_map_to_zone3_types(): void
    {
        $this->assertSame('Run', GarminActivityType::toInternal('RUNNING'));
        $this->assertSame('Run', GarminActivityType::toInternal('TRAIL_RUNNING'));
        $this->assertSame('Run', GarminActivityType::toInternal('TREADMILL_RUNNING'));
        $this->assertSame('VirtualRun', GarminActivityType::toInternal('VIRTUAL_RUN'));
        $this->assertSame('VirtualRide', GarminActivityType::toInternal('VIRTUAL_RIDE'));
        $this->assertSame('Ride', GarminActivityType::toInternal('ROAD_BIKING'));
        $this->assertSame('Ride', GarminActivityType::toInternal('GRAVEL_CYCLING'));
        $this->assertSame('Ride', GarminActivityType::toInternal('CYCLOCROSS'));
        $this->assertSame('EBikeRide', GarminActivityType::toInternal('E_BIKE_MOUNTAIN'));
        $this->assertSame('EBikeRide', GarminActivityType::toInternal('EBIKING'));
        $this->assertSame('Swim', GarminActivityType::toInternal('LAP_SWIMMING'));
        $this->assertSame('Walk', GarminActivityType::toInternal('CASUAL_WALKING'));
        $this->assertSame('Hike', GarminActivityType::toInternal('HIKING'));
        $this->assertSame('WeightTraining', GarminActivityType::toInternal('STRENGTH_TRAINING'));
        $this->assertSame('Workout', GarminActivityType::toInternal('RUN_TO_BIKE_TRANSITION'));
        $this->assertSame('Workout', GarminActivityType::toInternal('WHEELCHAIR_PUSH_RUN'));
        $this->assertSame('Workout', GarminActivityType::toInternal('PILATES'));
    }

    public function test_the_polyline_matches_googles_reference(): void
    {
        // Das Beispiel aus Googles Dokumentation des Formats.
        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', Polyline::encode([[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]]));
    }

    public function test_best_efforts_need_the_whole_distance(): void
    {
        $this->assertSame([], BestEffortService::effortsFromStream([[0, 0], [600, 900]]));

        $efforts = BestEffortService::effortsFromStream([[0, 0], [100, 500], [200, 1000], [400, 1500]]);
        $this->assertSame([['name' => '1k', 'distance' => 1000, 'elapsed_time' => 200]], $efforts);
    }
}
