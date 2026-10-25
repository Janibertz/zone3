<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Bike\BikeWorkouts;
use App\Services\Bike\FtpCheck;
use App\Services\Bike\ZwoWriter;
use App\Services\StravaImportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rad / Zwift: feste Radeinheiten in % FTP, der .zwo-Export und der Hinweis,
 * wenn eine Fahrt über der eingetragenen FTP lag.
 */
class BikeZwiftTest extends TestCase
{
    use RefreshDatabase;

    private int $stravaId = 900001;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function athlete(?int $ftp = 230): User
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->updateOrCreate([], ['ftp' => $ftp, 'bike_enabled' => true]);

        return $user->refresh();
    }

    private function ride(User $user, array $extra = []): Activity
    {
        return Activity::create($extra + [
            'user_id' => $user->id, 'strava_id' => $this->stravaId++, 'name' => 'Zwift - Watopia',
            'type' => 'VirtualRide', 'start_date' => now()->subDays(3), 'distance' => 30000,
            'moving_time' => 3600, 'elapsed_time' => 3600, 'average_speed' => 8.3,
            'average_watts' => 220, 'weighted_average_watts' => 250, 'device_watts' => true,
        ]);
    }

    // ── Feste Radeinheiten ───────────────────────────────────────────────

    public function test_an_easy_ride_fills_the_minutes_of_the_run_it_replaces(): void
    {
        $w = app(BikeWorkouts::class)->build(BikeWorkouts::EASY, 50, 250);

        $this->assertSame(50, $w['duration_min']);
        $this->assertSame('low', $w['intensity']);
        $main = collect($w['steps'])->firstWhere('type', 'work');
        $this->assertSame([0.60, 0.75], [$main['power_low'], $main['power_high']]);
        $this->assertSame('150–188 W', $main['watts']);
    }

    public function test_vo2_intervals_scale_with_the_time_available(): void
    {
        $short = app(BikeWorkouts::class)->build(BikeWorkouts::VO2, 50, 250);
        $long  = app(BikeWorkouts::class)->build(BikeWorkouts::VO2, 90, 250);

        $this->assertSame(4, collect($short['steps'])->firstWhere('type', 'work')['repetitions']);
        $this->assertSame(6, collect($long['steps'])->firstWhere('type', 'work')['repetitions']);
        $this->assertSame('high', $long['intensity']);
        $this->assertSame(15 + 6 * 8 + 10, $long['duration_min']);
    }

    public function test_without_ftp_the_steps_carry_no_watts(): void
    {
        $w = app(BikeWorkouts::class)->build(BikeWorkouts::THRESHOLD, 60, null);

        $this->assertNull(collect($w['steps'])->firstWhere('type', 'work')['watts']);
        $this->assertSame(0.95, collect($w['steps'])->firstWhere('type', 'work')['power_low']);
    }

    // ── .zwo ─────────────────────────────────────────────────────────────

    public function test_the_zwo_file_is_what_zwift_expects(): void
    {
        $w   = app(BikeWorkouts::class)->build(BikeWorkouts::VO2, 57, 250);
        $xml = simplexml_load_string(app(ZwoWriter::class)->write($w['title'], $w['description'], $w['steps']));

        $this->assertSame('bike', (string) $xml->sportType);
        $this->assertSame('Zone3', (string) $xml->author);

        $blocks = $xml->workout->children();
        $this->assertSame(['Warmup', 'IntervalsT', 'Cooldown'], array_map(fn ($b) => $b->getName(), iterator_to_array($blocks, false)));

        $set = $xml->workout->IntervalsT;
        $this->assertSame('4', (string) $set['Repeat']);
        $this->assertSame('240', (string) $set['OnDuration']);
        $this->assertSame('1.15', (string) $set['OnPower']);
        $this->assertSame('0.50', (string) $set['OffPower']);
        $this->assertSame('900', (string) $xml->workout->Warmup['Duration']);
    }

    public function test_an_easy_ride_is_a_steady_block(): void
    {
        $w   = app(BikeWorkouts::class)->build(BikeWorkouts::EASY, 60, null);
        $xml = simplexml_load_string(app(ZwoWriter::class)->write($w['title'], $w['description'], $w['steps']));

        $this->assertSame('2700', (string) $xml->workout->SteadyState['Duration']);
        $this->assertSame('0.68', (string) $xml->workout->SteadyState['Power']);
    }

    private function bikeSession(User $user, array $extra = []): TrainingSession
    {
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-03-14',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        return TrainingSession::create($extra + [
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => '2026-10-27', 'type' => 'cross_training', 'sport_type' => 'VirtualRide',
            'title' => 'Rad 4×4 min VO2max', 'description' => 'Hart', 'intensity' => 'high', 'status' => 'planned',
            'steps' => app(BikeWorkouts::class)->build(BikeWorkouts::VO2, 57, 230)['steps'],
        ]);
    }

    public function test_a_bike_session_downloads_as_zwo(): void
    {
        $user    = $this->athlete();
        $session = $this->bikeSession($user);

        $response = $this->actingAs($user)->get(route('training-sessions.zwo', $session));

        $response->assertOk();
        $this->assertStringContainsString('attachment; filename="zone3-2026-10-27-rad-4x4-min-vo2max.zwo"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('<IntervalsT Repeat="4"', $response->getContent());
    }

    public function test_a_run_has_no_zwo(): void
    {
        $user    = $this->athlete();
        $session = $this->bikeSession($user, [
            'type' => 'tempo_run', 'sport_type' => null, 'title' => 'Schwelle',
            'steps' => [['type' => 'work', 'label' => 'Schwelle', 'duration_min' => 20, 'pace_target' => '4:10', 'zone' => 4]],
        ]);

        $this->actingAs($user)->get(route('training-sessions.zwo', $session))->assertNotFound();
    }

    public function test_someone_elses_session_is_refused(): void
    {
        $session = $this->bikeSession($this->athlete());

        $this->actingAs($this->athlete())->get(route('training-sessions.zwo', $session))->assertForbidden();
    }

    // ── FTP-Hinweis ──────────────────────────────────────────────────────

    public function test_an_hour_at_250_np_proves_an_ftp_of_250(): void
    {
        $user = $this->athlete();

        $this->assertSame(250, app(FtpCheck::class)->estimate($this->ride($user)));
    }

    public function test_twenty_minutes_count_with_95_percent(): void
    {
        $user = $this->athlete();
        $ride = $this->ride($user, ['moving_time' => 1200, 'weighted_average_watts' => 260]);

        $this->assertSame(247, app(FtpCheck::class)->estimate($ride));
    }

    public function test_short_rides_and_estimated_watts_prove_nothing(): void
    {
        $user  = $this->athlete();
        $check = app(FtpCheck::class);

        $this->assertNull($check->estimate($this->ride($user, ['moving_time' => 900])));
        $this->assertNull($check->estimate($this->ride($user, ['type' => 'Ride', 'device_watts' => false])));
        // Ältere Fahrten ohne Herkunftsangabe: draußen nicht, auf Zwift schon.
        $this->assertNull($check->estimate($this->ride($user, ['type' => 'Ride', 'device_watts' => null])));
        $this->assertSame(250, $check->estimate($this->ride($user, ['device_watts' => null])));
        $this->assertNull($check->estimate($this->ride($user, ['type' => 'Run'])));
    }

    public function test_without_normalized_power_the_average_counts(): void
    {
        $user = $this->athlete();

        $this->assertSame(220, app(FtpCheck::class)->estimate($this->ride($user, ['weighted_average_watts' => null])));
    }

    public function test_the_activity_page_flags_a_ride_above_ftp(): void
    {
        $user = $this->athlete(230);
        $ride = $this->ride($user);

        $this->actingAs($user)->get(route('activities.show', $ride))
            ->assertInertia(fn ($page) => $page
                ->where('ftpHint.estimate', 250)
                ->where('ftpHint.current', 230)
                ->where('activity.weighted_average_watts', 250));
    }

    /** 3 % Luft: eine gute Tagesform ist noch keine neue FTP. */
    public function test_a_ride_within_the_margin_is_not_flagged(): void
    {
        $user = $this->athlete(245);
        $ride = $this->ride($user);

        $this->actingAs($user)->get(route('activities.show', $ride))
            ->assertInertia(fn ($page) => $page->where('ftpHint', null));
    }

    public function test_the_profile_suggests_the_strongest_recent_ride(): void
    {
        $user = $this->athlete(230);
        $this->ride($user, ['name' => 'Schwach', 'weighted_average_watts' => 240]);
        $this->ride($user, ['name' => 'Stark', 'weighted_average_watts' => 262]);
        $this->ride($user, ['name' => 'Zu alt', 'weighted_average_watts' => 300, 'start_date' => now()->subDays(60)]);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('ftpSuggestion.estimate', 262)
                ->where('ftpSuggestion.activity_name', 'Stark'));
    }

    public function test_no_suggestion_when_the_ftp_holds(): void
    {
        $user = $this->athlete(270);
        $this->ride($user);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page->where('ftpSuggestion', null));
    }

    public function test_the_import_keeps_normalized_power_and_its_source(): void
    {
        $user = $this->athlete();

        $activity = app(StravaImportService::class)->importFromDetail($user->id, [
            'id' => 4242, 'name' => 'Zwift', 'type' => 'VirtualRide', 'distance' => 30000,
            'moving_time' => 3600, 'elapsed_time' => 3600, 'start_date' => '2026-10-24T18:00:00Z',
            'average_watts' => 210, 'weighted_average_watts' => 238, 'device_watts' => true,
        ]);

        $this->assertSame(238, $activity->fresh()->weighted_average_watts);
        $this->assertTrue($activity->fresh()->device_watts);
    }
}
