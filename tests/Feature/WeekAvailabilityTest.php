<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die Wochenabfrage traegt Ausnahmen fuer genau eine Woche ein — Urlaub,
 * Schichtdienst, volle Woche. Das Wochenraster im Profil bleibt unberuehrt.
 */
class WeekAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ein Montag, damit die Abfaellig­keit deterministisch ist.
        Carbon::setTestNow('2026-08-17 08:00:00');
        CarbonImmutable::setTestNow('2026-08-17 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function athleteWithPlan(): array
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->create(['threshold_speed' => 5.5]);

        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(60),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);

        $plan = TrainingPlan::create([
            'user_id'  => $user->id,
            'event_id' => $event->id,
            'sessions' => [],
        ]);
        $plan->forceFill(['is_active' => true])->save();

        return [$user->refresh(), $plan];
    }

    public function test_the_dashboard_asks_on_monday(): void
    {
        [$user] = $this->athleteWithPlan();

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('weekCheck.weekStart', '2026-08-17')
                ->has('weekCheck.days', 7));
    }

    /** Mitten in der Woche soll die Abfrage nicht stoeren. */
    public function test_the_dashboard_stays_quiet_on_a_wednesday(): void
    {
        Carbon::setTestNow('2026-08-19 08:00:00');
        CarbonImmutable::setTestNow('2026-08-19 08:00:00');

        [$user] = $this->athleteWithPlan();

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('weekCheck', null));
    }

    public function test_confirming_silences_the_question_for_this_week(): void
    {
        [$user] = $this->athleteWithPlan();

        $this->actingAs($user)->postJson(route('week-availability.confirm'))->assertOk();

        $this->assertSame('2026-W34', $user->refresh()->runnerProfile->week_check_week);

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('weekCheck', null));
    }

    public function test_changed_days_land_as_overrides_and_trigger_a_new_plan(): void
    {
        [$user, $plan] = $this->athleteWithPlan();

        $this->actingAs($user)->postJson(route('week-availability.store'), [
            'days' => [
                ['date' => '2026-08-18', 'available' => false],
                ['date' => '2026-08-20', 'available' => true, 'duration_min' => 120],
            ],
        ])->assertOk();

        $plan->refresh();
        $this->assertFalse($plan->availability_overrides['2026-08-18']['available']);
        $this->assertSame(120, $plan->availability_overrides['2026-08-20']['duration_min']);
        $this->assertTrue((bool) $plan->needs_plan_update);
    }

    /** Ueber diesen Weg darf nur die kommende Woche geaendert werden. */
    public function test_days_outside_the_week_are_ignored(): void
    {
        [$user, $plan] = $this->athleteWithPlan();

        $this->actingAs($user)->postJson(route('week-availability.store'), [
            'days' => [['date' => '2026-09-30', 'available' => false]],
        ])->assertOk();

        $this->assertArrayNotHasKey('2026-09-30', $plan->refresh()->availability_overrides ?? []);
    }

    /** Das Raster im Profil bleibt, was es ist. */
    public function test_the_weekly_grid_is_not_touched(): void
    {
        [$user] = $this->athleteWithPlan();
        $grid   = ['monday' => ['available' => true, 'duration_min' => 60]];
        $user->runnerProfile->update(['weekly_availability' => $grid]);

        $this->actingAs($user)->postJson(route('week-availability.store'), [
            'days' => [['date' => '2026-08-18', 'available' => false]],
        ])->assertOk();

        $this->assertSame($grid, $user->refresh()->runnerProfile->weekly_availability);
    }

    public function test_without_an_active_plan_there_is_nowhere_to_store_it(): void
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->create(['threshold_speed' => 5.5]);

        $this->actingAs($user->refresh())->postJson(route('week-availability.store'), [
            'days' => [['date' => '2026-08-18', 'available' => false]],
        ])->assertStatus(422);
    }
}
