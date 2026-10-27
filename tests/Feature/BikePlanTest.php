<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Jobs\RegeneratePlanJob;
use App\Services\AI\CoachChatService;
use App\Services\AI\TrainingPlanGenerator;
use App\Services\PlanContext;
use App\Services\PlanContextBuilder;
use App\Services\WebPushService;
use App\Services\Bike\BikeWorkouts;
use App\Services\ActivityImportService;
use App\Services\TrainingPlanValidator;
use App\Services\WeeklyPatternService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rad im Plan (Entscheidung vom 25.10.2026): Rad ersetzt lockere Läufe bis
 * zum Wochenmaximum, höchstens ein Radintervall statt der VO2-Intervalle,
 * die Doppel-Schwelle bleibt Laufen, keine Radintervalle in Taper und
 * Rennwoche, und die Kilometer des Radtags fallen aus dem Laufumfang.
 *
 * Halbmarathon am 29.11.2026 wie in NorwegianModelTest: die Woche ab
 * 26.10. ist Peak und Belastung, ab 23.11. Rennwoche.
 */
class BikePlanTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY     = '2026-08-31';
    private const RACE_DAY  = '2026-11-29';
    private const PEAK_WEEK = '2026-10-26';

    private const BIKE = [
        'season' => 'all_year', 'purpose' => 'both', 'max_per_week' => 2,
        'preferred_days' => [], 'ftp' => 250,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY);
        CarbonImmutable::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function event(?User $user = null): Event
    {
        return Event::create([
            'user_id' => ($user ?? User::factory()->create())->id, 'name' => 'Halbmarathon',
            'event_date' => self::RACE_DAY, 'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
    }

    private function availability(array $evenings = []): array
    {
        return collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => 90]
                + (isset($evenings[$d]) ? ['second_min' => $evenings[$d]] : [])])
            ->all();
    }

    private function week(string $monday = self::PEAK_WEEK, ?array $bike = self::BIKE, ?array $model = null, array $evenings = []): array
    {
        $from = CarbonImmutable::parse($monday);

        return app(WeeklyPatternService::class)->build(
            $this->event(), $from, $from->addDays(6), $this->availability($evenings),
            [], [], null, null,
            ['has_data' => true, 'next_week_max' => 60], 330,
            [], $model, $bike,
        );
    }

    /** @return \Illuminate\Support\Collection<int, array> */
    private function slots(array $skeleton)
    {
        return collect($skeleton['days'])
            ->flatMap(fn ($d, $date) => collect($d['slots'] ?? [])->map(fn ($s) => $s + ['date' => $date]))
            ->values();
    }

    // ── Gerüst ───────────────────────────────────────────────────────────

    public function test_an_easy_run_and_the_interval_go_on_the_bike(): void
    {
        $slots = $this->slots($this->week());
        $bikes = $slots->whereNotNull('bike');

        $this->assertSame(['easy', 'vo2'], $bikes->pluck('bike')->sort()->values()->all());
        $this->assertSame(['easy_run', 'interval'], $bikes->sortBy('bike')->pluck('replaces')->values()->all());
        $this->assertTrue($bikes->every(fn ($s) => $s['type'] === 'cross_training' && $s['sport'] === 'VirtualRide'));

        // Langer Lauf und Schwelle bleiben Laufen.
        $this->assertNotNull($slots->firstWhere('type', 'long_run'));
        $this->assertNotNull($slots->firstWhere('type', 'tempo_run'));
        $this->assertNull($slots->firstWhere('type', 'interval'));
    }

    public function test_an_easy_ride_lasts_a_quarter_longer_than_the_run(): void
    {
        $without = $this->slots($this->week(bike: null))->firstWhere('type', 'easy_run');
        $ride    = $this->slots($this->week())->firstWhere('bike', 'easy');

        $this->assertSame((int) round($without['target_min'] * BikeWorkouts::EASY_DURATION_FACTOR), $ride['target_min']);
        $this->assertSame($ride['target_min'], $ride['workout']['duration_min']);
        $this->assertSame('150–188 W', collect($ride['workout']['steps'])->firstWhere('type', 'work')['watts']);
    }

    /** Regel 2: das Radintervall bleibt hart und hält den Hauptteil, den 80/20 gelassen hat. */
    public function test_the_bike_interval_stays_hard_within_its_eighty_twenty_minutes(): void
    {
        $run  = $this->slots($this->week(bike: null))->firstWhere('type', 'interval');
        $ride = $this->slots($this->week())->firstWhere('bike', 'vo2');

        $this->assertTrue($ride['hard']);
        $this->assertLessThanOrEqual($run['hard_min'], $ride['hard_min']);
        $reps = collect($ride['workout']['steps'])->firstWhere('type', 'work')['repetitions'];
        $this->assertSame($reps * BikeWorkouts::VO2_REP_MINUTES, $ride['hard_min']);
    }

    /** Regel 5: die Kilometer fallen aus der Woche, sie wandern nicht zu den anderen Läufen. */
    public function test_the_ridden_kilometres_leave_the_running_week(): void
    {
        $runKm = fn ($sk) => $this->slots($sk)
            ->filter(fn ($s) => in_array($s['type'], WeeklyPatternService::RUN_SLOT_TYPES, true))
            ->sum('target_km');

        $without = $this->week(bike: null);
        $with    = $this->week();

        $this->assertLessThan($runKm($without), $runKm($with));

        $easyBefore = $this->slots($without)->where('type', 'easy_run')->pluck('target_km')->unique()->all();
        $easyAfter  = $this->slots($with)->where('type', 'easy_run')->pluck('target_km')->unique()->all();
        $this->assertSame($easyBefore, $easyAfter, 'Die übrigen lockeren Läufe werden nicht länger');
    }

    public function test_preferred_days_come_first_and_the_maximum_holds(): void
    {
        $slots = $this->slots($this->week(bike: ['purpose' => 'easy', 'max_per_week' => 1, 'preferred_days' => ['sunday']] + self::BIKE));
        $bikes = $slots->whereNotNull('bike');

        $this->assertCount(1, $bikes);
        $this->assertSame('2026-11-01', $bikes->first()['date']);
        $this->assertNotNull($slots->firstWhere('type', 'interval'), 'Zweck „locker" lässt die Intervalle laufen');
    }

    public function test_the_interval_counts_against_the_weekly_maximum(): void
    {
        $bikes = $this->slots($this->week(bike: ['max_per_week' => 1] + self::BIKE))->whereNotNull('bike');

        $this->assertSame(['vo2'], $bikes->pluck('bike')->values()->all());
    }

    public function test_winter_only_means_no_bike_before_november(): void
    {
        $winter = ['season' => 'winter'] + self::BIKE;

        $this->assertCount(0, $this->slots($this->week('2026-10-19', bike: $winter))->whereNotNull('bike'));
        $this->assertNotEmpty($this->slots($this->week('2026-11-02', bike: $winter))->whereNotNull('bike'));
    }

    /** Regel 4: Rennwoche — lockeres Rad ja, Radintervall nein. */
    public function test_no_bike_intervals_in_race_week(): void
    {
        $bikes = $this->slots($this->week('2026-11-23'))->whereNotNull('bike');

        $this->assertNotEmpty($bikes);
        $this->assertSame([], $bikes->where('bike', 'vo2')->all());
    }

    /** Regel 3: im norwegischen Modell bleibt die Doppel-Schwelle Laufen, die schnelle Einheit darf aufs Rad. */
    public function test_the_norwegian_double_threshold_stays_running(): void
    {
        $model = ['name' => 'norwegian', 'threshold' => '4:10', 'sub_pace' => '4:15–4:25', 'hr_max' => 165];
        $slots = $this->slots($this->week(model: $model, evenings: ['tuesday' => 45, 'thursday' => 45]));

        $this->assertCount(4, $slots->filter(fn ($s) => ! empty($s['double'])), 'beide Doppel-Tage komplett');
        $this->assertTrue($slots->whereNotNull('bike')->every(fn ($s) => in_array($s['replaces'], ['easy_run', 'interval'], true)));
        $fast = $slots->firstWhere('bike', 'fast');
        $this->assertNotNull($fast, 'die kurze schnelle Einheit geht aufs Rad — als kurze Stöße, nicht als VO2-Block');
        $this->assertSame('interval', $fast['replaces']);
        $this->assertLessThanOrEqual(10, $fast['hard_min'], 'innerhalb des 5-%-Anteils');
    }

    public function test_without_bike_settings_nothing_changes(): void
    {
        $this->assertSame([], $this->slots($this->week(bike: null))->whereNotNull('bike')->all());
    }

    public function test_the_prompt_names_the_bike_day_and_asks_for_no_entry(): void
    {
        $text = app(WeeklyPatternService::class)->toPromptSection($this->week());

        $this->assertStringContainsString('RADEINHEIT (Zwift', $text);
        $this->assertStringContainsString('gleiche sie NICHT an anderen Tagen aus', $text);
        $this->assertStringContainsString('Rad statt Laufen', $text);
    }

    // ── Validator ────────────────────────────────────────────────────────

    public function test_the_validator_puts_the_ride_where_the_model_wrote_a_run(): void
    {
        $skeleton = $this->week();
        $date     = $this->slots($skeleton)->firstWhere('bike', 'easy')['date'];

        $sessions = collect($skeleton['days'])->keys()->map(fn ($d) => [
            'date' => $d, 'type' => 'easy_run', 'title' => 'Locker', 'description' => '',
            'distance_km' => 7, 'duration_min' => 40, 'pace_target' => null, 'zone' => 2, 'intensity' => 'low',
        ])->all();

        $result = app(TrainingPlanValidator::class)->validate($sessions, $skeleton, self::RACE_DAY);
        $onDay  = collect($result['sessions'])->where('date', $date)->values();

        $ride = $onDay->firstWhere('type', 'cross_training');
        $this->assertNotNull($ride);
        $this->assertSame('VirtualRide', $ride['sport_type']);
        $this->assertNotEmpty($ride['steps']);
        $this->assertNull($onDay->firstWhere('type', 'easy_run'), 'der Lauf des Modells ist weg');
        $this->assertArrayNotHasKey('_locked', $ride);
    }

    // ── Strava ───────────────────────────────────────────────────────────

    /** @return array{0: User, 1: TrainingPlan} */
    private function athleteWithPlan(): array
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        return [$user, $plan];
    }

    private function plannedRide(User $user, TrainingPlan $plan, string $date = '2026-09-02'): TrainingSession
    {
        $w = app(BikeWorkouts::class)->build(BikeWorkouts::EASY, 50, 250);

        return TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $plan->event_id,
            'planned_date' => $date, 'type' => 'cross_training', 'sport_type' => 'VirtualRide',
            'title' => $w['title'], 'description' => $w['description'], 'duration_min' => $w['duration_min'],
            'intensity' => 'low', 'status' => 'planned', 'steps' => $w['steps'],
        ]);
    }

    private function activity(User $user, string $type, string $date = '2026-09-02'): Activity
    {
        return Activity::create([
            'user_id' => $user->id, 'strava_id' => random_int(1, 999999), 'name' => "{$type} am Abend",
            'type' => $type, 'start_date' => "{$date} 18:00:00", 'distance' => 25000,
            'moving_time' => 3300, 'elapsed_time' => 3300, 'average_speed' => 7.5,
        ]);
    }

    public function test_a_zwift_ride_completes_the_planned_ride(): void
    {
        [$user, $plan] = $this->athleteWithPlan();
        $session = $this->plannedRide($user, $plan);
        $ride    = $this->activity($user, 'VirtualRide');

        app(ActivityImportService::class)->matchActivityToSession($user->id, $ride);

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame($ride->id, $session->activity_id);
        $this->assertSame(55, $session->duration_min);
        $this->assertSame('Rad locker', $session->planned_snapshot['title']);
        $this->assertSame(1, TrainingSession::where('user_id', $user->id)->count(), 'keine ungeplante Zusatzeinheit');
    }

    public function test_a_run_does_not_complete_the_planned_ride(): void
    {
        [$user, $plan] = $this->athleteWithPlan();
        $session = $this->plannedRide($user, $plan);

        app(ActivityImportService::class)->matchActivityToSession($user->id, $this->activity($user, 'Run'));

        $this->assertSame('planned', $session->fresh()->status);
    }

    public function test_a_planned_ride_downloads_as_zwo_from_the_plan_page(): void
    {
        [$user, $plan] = $this->athleteWithPlan();
        $session = $this->plannedRide($user, $plan);

        $this->actingAs($user)->get(route('events.plan.show', $plan->event_id))
            ->assertInertia(fn ($page) => $page->where('sessions.0.zwo_url', route('training-sessions.zwo', $session)));
    }

    // ── Coach ────────────────────────────────────────────────────────────

    private function tool(User $user, array $args): array
    {
        $method = new \ReflectionMethod(CoachChatService::class, 'executeCoachTool');
        $method->setAccessible(true);

        return $method->invoke(app(CoachChatService::class), $user->fresh(), 'switch_to_bike', $args);
    }

    private function plannedRun(User $user, TrainingPlan $plan, string $type, int $minutes): TrainingSession
    {
        return TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $plan->event_id,
            'planned_date' => '2026-09-01', 'type' => $type, 'title' => 'Lauf', 'description' => '',
            'duration_min' => $minutes, 'distance_km' => 8, 'pace_target' => '5:30', 'zone' => 2,
            'intensity' => 'low', 'status' => 'planned', 'nutrition_tips' => ['before' => []],
        ]);
    }

    /** Über den Chat darf die Radeinheit über das Tagesfenster gehen (Entscheidung von Jan). */
    public function test_the_coach_swaps_an_easy_run_for_a_longer_ride(): void
    {
        [$user, $plan] = $this->athleteWithPlan();
        $user->runnerProfile()->create([
            'ftp' => 240,
            'weekly_availability' => ['tuesday' => ['available' => true, 'duration_min' => 40]],
        ]);
        $run = $this->plannedRun($user, $plan, 'easy_run', 40);

        $result = $this->tool($user, ['date' => '2026-09-01']);

        $run->refresh();
        $this->assertNotNull($result['action']);
        $this->assertSame('cross_training', $run->type);
        $this->assertSame('VirtualRide', $run->sport_type);
        $this->assertSame(50, $run->duration_min, '40 min × 1,25, nicht auf die 40 min des Tages gedeckelt');
        $this->assertNull($run->distance_km);
        $this->assertNull($run->pace_target);
        $this->assertNull($run->nutrition_tips);
        $this->assertNotNull($run->pinned_at);
        $this->assertTrue($run->hasBikeSteps());
    }

    public function test_the_coach_keeps_a_long_run_on_foot(): void
    {
        [$user, $plan] = $this->athleteWithPlan();
        $run = $this->plannedRun($user, $plan, 'long_run', 100);

        $result = $this->tool($user, ['date' => '2026-09-01']);

        $this->assertNull($result['action']);
        $this->assertSame('long_run', $run->fresh()->type);
    }

    public function test_the_coach_turns_intervals_into_bike_intervals(): void
    {
        [$user, $plan] = $this->athleteWithPlan();
        $run = $this->plannedRun($user, $plan, 'interval', 60);

        $this->tool($user, ['date' => '2026-09-01']);

        $this->assertStringContainsString('VO2max', $run->fresh()->title);
        $this->assertSame('high', $run->fresh()->intensity);
    }

    // ── Ende zu Ende ─────────────────────────────────────────────────────

    /** Die Neuberechnung schreibt die Radeinheit mit Sportart und Schritten — das Modell liefert nur Läufe. */
    public function test_a_regeneration_writes_the_rides_with_their_steps(): void
    {
        [$user, $plan] = $this->athleteWithPlan();
        $user->runnerProfile()->updateOrCreate([], [
            'threshold_speed' => 5.0, 'weekly_availability' => $this->availability(), 'ftp' => 250,
            'bike_enabled' => true, 'bike_season' => 'all_year', 'bike_purpose' => 'both', 'bike_max_per_week' => 2,
        ]);
        $plan->forceFill(['needs_plan_update' => true])->save();

        $this->mock(WebPushService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturnNull());
        $this->mock(TrainingPlanGenerator::class, function ($m) {
            $m->shouldReceive('withCoach')->andReturnSelf();
            $m->shouldReceive('forUser')->andReturnSelf();
            $m->shouldReceive('generateEventTrainingPlan')->andReturnUsing(fn (PlanContext $c) => collect($c->skeleton['days'])
                ->map(fn ($day, $date) => [
                    'date' => $date, 'type' => 'easy_run', 'title' => 'Vom Modell', 'description' => '',
                    'distance_km' => 7, 'duration_min' => 40, 'pace_target' => '6:00', 'zone' => 2, 'intensity' => 'low',
                ])->values()->all());
        });

        (new RegeneratePlanJob($user->id, RegeneratePlanJob::REASON_MANUAL))->handle(
            app(TrainingPlanGenerator::class), app(WebPushService::class), app(PlanContextBuilder::class),
        );

        $rides = TrainingSession::where('user_id', $user->id)->where('type', 'cross_training')->get();

        $this->assertNotEmpty($rides);
        foreach ($rides as $ride) {
            $this->assertSame('VirtualRide', $ride->sport_type);
            $this->assertTrue($ride->hasBikeSteps());
            $this->assertSame(0, TrainingSession::where('user_id', $user->id)->whereDate('planned_date', $ride->planned_date)
                ->whereIn('type', TrainingPlanValidator::RUN_TYPES)->count(), 'kein Lauf neben der Radeinheit');
        }
    }
}
