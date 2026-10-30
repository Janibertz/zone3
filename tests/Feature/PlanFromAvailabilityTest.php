<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\FatigueCheck;
use App\Services\LongRunPlanService;
use App\Services\PeriodizationService;
use App\Services\PlanContextBuilder;
use App\Services\WeeklyPatternService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jans Rückmeldung zum ersten Plan nach PR #51 (30.10.2026): „Der Plan soll
 * sich nach meiner Verfügbarkeit richten … + Krafttraining + Rad."
 *
 *   · Wiedereinstieg nur, wenn Krankheit oder Verletzung einen laufenden
 *     Plan unterbricht — nicht wegen einer Pause vor dem Planstart
 *   · die erste Entlastung frühestens nach drei Belastungswochen ab Planstart
 *   · Kraft so oft wie eingestellt, fest, zuerst auf freie Tage
 *   · Entlastung vorziehen, wenn Garmin und Tagesform nach Ermüdung aussehen
 */
class PlanFromAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::TODAY));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY));
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
            'monday'    => ['available' => true,  'duration_min' => 60],
            'tuesday'   => ['available' => true,  'duration_min' => 60, 'fixed' => ['type' => 'easy_run', 'label' => 'Laufclub']],
            'wednesday' => ['available' => true,  'duration_min' => 30],
            'thursday'  => ['available' => false, 'duration_min' => 0],
            'friday'    => ['available' => true,  'duration_min' => 120],
            'saturday'  => ['available' => true,  'duration_min' => 120],
            'sunday'    => ['available' => true,  'duration_min' => 180],
        ];
    }

    private function athlete(?string $planStart = '2026-11-30'): array
    {
        $user = User::factory()->create();
        RunnerProfile::create([
            'user_id' => $user->id, 'threshold_speed' => 4.25,
            'weekly_availability' => $this->grid(),
            'strength_enabled' => true, 'strength_days_per_week' => 2,
        ]);

        // Eine Laufroutine bis Mitte Oktober, danach Pause.
        foreach (range(10, 40, 3) as $i => $daysAgo) {
            Activity::create([
                'user_id' => $user->id, 'strava_id' => 7000 + $i, 'name' => 'Lauf', 'type' => 'Run',
                'distance' => 10000, 'moving_time' => 3300, 'elapsed_time' => 3300,
                'average_speed' => 3.0, 'start_date' => Carbon::parse(self::TODAY)->subDays($daysAgo + 5),
            ]);
        }

        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Halbmarathon', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 25,
            'plan_start_date' => $planStart,
        ]);

        return [$user, $event];
    }

    private function wellbeing(User $user, string $date, array $attrs = []): void
    {
        WellbeingEntry::create($attrs + [
            'user_id' => $user->id, 'date' => $date, 'energy_level' => 7, 'mood' => 7,
            'sleep_quality' => 7, 'muscle_soreness' => 2, 'stress_level' => 3,
        ]);
    }

    // ── Wiedereinstieg ───────────────────────────────────────────────────

    public function test_an_injury_before_the_plan_starts_is_no_comeback(): void
    {
        [$user, $event] = $this->athlete();
        $this->wellbeing($user, '2026-10-26', ['is_injured' => true]);

        $context = app(PlanContextBuilder::class)->build($user, $event, []);

        $this->assertNull($context->comeback);
        $this->assertNull($context->skeleton['comeback']);
    }

    public function test_a_break_without_illness_is_no_comeback(): void
    {
        [$user, $event] = $this->athlete(null);

        $this->assertNull(app(PlanContextBuilder::class)->build($user, $event, [])->comeback);
    }

    public function test_illness_during_a_running_plan_is_a_comeback(): void
    {
        [$user, $event] = $this->athlete(null);
        $this->wellbeing($user, '2026-10-29', ['is_sick' => true]);

        $this->assertNotNull(app(PlanContextBuilder::class)->build($user, $event, [])->comeback);
    }

    // ── Entlastung ───────────────────────────────────────────────────────

    public function test_no_recovery_week_in_the_first_three_weeks(): void
    {
        [, $event] = $this->athlete();
        $periods   = app(PeriodizationService::class);

        // Der Rhythmus vom Renntag aus legte die erste Entlastung auf W50.
        foreach (['2026-11-30', '2026-12-07', '2026-12-14'] as $monday) {
            $this->assertFalse($periods->forWeek($event, CarbonImmutable::parse($monday))['recovery'], $monday);
        }

        // Danach läuft der Rhythmus weiter.
        $later = collect(range(3, 12))->map(fn ($w) => $periods->forWeek($event, CarbonImmutable::parse('2026-11-30')->addWeeks($w)));
        $this->assertTrue($later->contains('recovery', true));
    }

    public function test_fatigue_pulls_the_recovery_week_forward(): void
    {
        [$user, $event] = $this->athlete(null);
        foreach (['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29'] as $d) {
            $this->wellbeing($user, $d, ['energy_level' => 3, 'muscle_soreness' => 8]);
        }

        $this->assertCount(2, app(FatigueCheck::class)->signals($user));

        app(PlanContextBuilder::class)->build($user, $event, []);

        $this->assertSame('2026-11-02', $event->fresh()->early_recovery_week->toDateString());
        $this->assertTrue(app(PeriodizationService::class)->forWeek($event->fresh(), CarbonImmutable::parse('2026-11-02'))['recovery']);
    }

    public function test_one_signal_is_not_enough(): void
    {
        [$user, $event] = $this->athlete(null);
        foreach (['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29'] as $d) {
            $this->wellbeing($user, $d, ['energy_level' => 3]);
        }

        app(PlanContextBuilder::class)->build($user, $event, []);

        $this->assertNull($event->fresh()->early_recovery_week);
    }

    // ── Kraft ────────────────────────────────────────────────────────────

    private function skeleton(?array $strength): array
    {
        [, $event] = $this->athlete();
        $volume    = ['has_data' => true, 'next_week_max' => 35.9, 'longest_run' => 21.3];
        $from      = CarbonImmutable::parse('2026-11-30');
        $ladder    = app(LongRunPlanService::class)->forEvent($event, $volume, 335, 180, $from);

        return app(WeeklyPatternService::class)->build(
            $event, $from, $from->addDays(13), $this->grid(), [], [], null, $ladder, $volume, 335, [], null, null, $strength,
        )['days'];
    }

    public function test_strength_comes_as_often_as_set_and_is_not_optional(): void
    {
        $days = $this->skeleton(['enabled' => true, 'days_per_week' => 2]);

        foreach ([['2026-11-30', '2026-12-06'], ['2026-12-07', '2026-12-13']] as [$from, $to]) {
            $strength = collect($days)
                ->filter(fn ($d, $date) => $date >= $from && $date <= $to)
                ->flatMap(fn ($d) => $d['slots'])
                ->where('type', 'strength');

            $this->assertCount(2, $strength, "Woche ab {$from}");
            $this->assertTrue($strength->every(fn ($s) => empty($s['optional'])));
        }

        // Zuerst auf den freien Montag.
        $this->assertSame(['strength'], array_column($days['2026-11-30']['slots'], 'type'));
    }

    public function test_no_strength_the_day_before_a_long_or_hard_run(): void
    {
        $days = $this->skeleton(['enabled' => true, 'days_per_week' => 4]);

        foreach ($days as $date => $day) {
            if (! collect($day['slots'])->contains('type', 'strength')) {
                continue;
            }
            $next = $days[CarbonImmutable::parse($date)->addDay()->format('Y-m-d')]['slots'] ?? [];
            $this->assertFalse(collect($next)->contains(fn ($s) => $s['type'] === 'long_run' || ! empty($s['hard'])), $date);
        }
    }

    public function test_without_a_strength_wish_nothing_is_fixed(): void
    {
        $days = $this->skeleton(['enabled' => false]);

        $fixed = collect($days)->flatMap(fn ($d) => $d['slots'])
            ->filter(fn ($s) => $s['type'] === 'strength' && empty($s['optional']));
        $this->assertCount(0, $fixed);
    }
}
