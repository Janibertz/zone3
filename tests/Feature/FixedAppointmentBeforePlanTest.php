<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Garmin\GarminWorkoutSync;
use App\Services\Plan\FixedAppointment;
use App\Services\Widget\WidgetPayload;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gemeldet von Jan am 15.11.2026: Plan ab 30.11., Laufclub jeden Dienstag,
 * aber der Dienstag davor stand weder im Kalender noch im Widget. Und eine
 * Ausnahme in „Deine Woche" mit anderer Dauer strich den Termin — er soll
 * nur ausfallen, wenn der Tag gesperrt ist.
 */
class FixedAppointmentBeforePlanTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-15'; // Sonntag

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY . ' 18:00:00');
        CarbonImmutable::setTestNow(self::TODAY . ' 18:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function grid(): array
    {
        return [
            'monday'    => ['available' => true, 'duration_min' => 60],
            'tuesday'   => ['available' => true, 'duration_min' => 60, 'fixed' => ['type' => 'interval', 'label' => 'KRAFT Laufclub']],
            'wednesday' => ['available' => true, 'duration_min' => 30],
            'thursday'  => ['available' => false, 'duration_min' => 0],
            'friday'    => ['available' => true, 'duration_min' => 120],
            'saturday'  => ['available' => true, 'duration_min' => 120],
            'sunday'    => ['available' => true, 'duration_min' => 180],
        ];
    }

    /** @return array{0: User, 1: TrainingPlan} */
    private function athlete(?string $planStart = '2026-11-30', array $overrides = []): array
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->create(['threshold_speed' => 4.33, 'weekly_availability' => $this->grid()]);

        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'plan_start_date' => $planStart,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true, 'availability_overrides' => $overrides ?: null])->save();

        return [$user->refresh(), $plan];
    }

    public function test_tuesdays_before_the_plan_start_are_listed(): void
    {
        [$user] = $this->athlete();

        $dates = collect(FixedAppointment::unplanned($user, CarbonImmutable::today(), CarbonImmutable::parse('2026-12-31')))
            ->pluck('planned_date')->all();

        // Ab dem 01.12. trägt der Plan den Termin selbst.
        $this->assertSame(['2026-11-17', '2026-11-24'], $dates);
    }

    public function test_once_the_plan_runs_nothing_is_added(): void
    {
        [$user] = $this->athlete(null);

        $this->assertSame([], FixedAppointment::unplanned($user, CarbonImmutable::today(), CarbonImmutable::parse('2026-12-31')));
    }

    public function test_a_blocked_day_or_a_run_there_drops_it(): void
    {
        [$user] = $this->athlete('2026-11-30', ['2026-11-17' => ['available' => false, 'duration_min' => 0]]);
        TrainingSession::create([
            'user_id' => $user->id, 'planned_date' => '2026-11-24', 'type' => 'easy_run',
            'title' => 'Locker', 'intensity' => 'low', 'status' => 'planned',
        ]);

        $this->assertSame([], FixedAppointment::unplanned($user, CarbonImmutable::today(), CarbonImmutable::parse('2026-11-29')));
    }

    /** Eine Radeinheit am selben Tag verdrängt den Laufclub nicht. */
    public function test_a_bike_session_keeps_it(): void
    {
        [$user, $plan] = $this->athlete();
        TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'planned_date' => '2026-11-17',
            'type' => 'cross_training', 'sport_type' => 'VirtualRide', 'title' => 'Rad locker', 'intensity' => 'low', 'status' => 'planned',
        ]);

        $this->assertCount(1, FixedAppointment::unplanned($user, CarbonImmutable::today(), CarbonImmutable::parse('2026-11-17')));
    }

    public function test_the_widget_shows_it(): void
    {
        [$user] = $this->athlete();

        $days = collect(app(WidgetPayload::class)->build($user)['days'])->keyBy('date');

        $this->assertSame('KRAFT Laufclub', $days['2026-11-17']['items'][0]['title']);
        $this->assertSame('planned', $days['2026-11-17']['items'][0]['status']);
    }

    /** Andere Dauer = Termin bleibt; gesperrt = Termin fällt aus. */
    public function test_only_a_blocked_day_cancels_the_appointment(): void
    {
        $weekly  = $this->grid();
        $tuesday = CarbonImmutable::parse('2026-12-01');

        $this->assertNotNull(FixedAppointment::on($weekly, ['2026-12-01' => ['available' => true, 'duration_min' => 90]], $tuesday));
        $this->assertNull(FixedAppointment::on($weekly, ['2026-12-01' => ['available' => false, 'duration_min' => 0]], $tuesday));

        $session = new TrainingSession(['type' => 'interval', 'planned_date' => '2026-12-01']);
        $this->assertTrue(GarminWorkoutSync::isFixedAppointment($session, $weekly, ['2026-12-01' => ['available' => true, 'duration_min' => 90]]));
    }

    public function test_the_skeleton_keeps_the_club_with_a_changed_duration(): void
    {
        [, $plan] = $this->athlete();
        $days = app(\App\Services\WeeklyPatternService::class)->build(
            $plan->event,
            CarbonImmutable::parse('2026-11-30'),
            CarbonImmutable::parse('2026-12-06'),
            $this->grid(),
            ['2026-12-01' => ['available' => true, 'duration_min' => 90]],
        );

        $slots = collect($days['days']['2026-12-01']['slots'] ?? []);
        $this->assertTrue($slots->contains(fn ($s) => ! empty($s['fixed'])));
    }

    /** Jan, 16.11.2026: „Als Nächstes" und die Planseite zeigten am Dienstag nur Rad. */
    public function test_dashboard_and_plan_page_list_it_next_to_the_bike(): void
    {
        $this->withoutVite();
        [$user, $plan] = $this->athlete();
        TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'planned_date' => '2026-11-17',
            'type' => 'cross_training', 'sport_type' => 'VirtualRide', 'title' => 'Rad locker', 'intensity' => 'low', 'status' => 'planned',
        ]);

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('upcomingSessions', fn ($list) => collect($list)->pluck('id')->contains('fixed-2026-11-17')));

        $this->actingAs($user)->get("/events/{$plan->event_id}/plan")->assertInertia(fn ($page) => $page
            ->where('sessions', fn ($list) => collect($list)->where('planned_date', '2026-11-17')->pluck('status')->sort()->values()->all() === ['appointment', 'planned']));
    }
}
