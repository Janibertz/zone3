<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Moments\MomentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** „Woche geschafft“ und „Rennen gelaufen“ auf Heute (09.11.2026). */
class WeekAndRaceMomentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 10:00:00'); // Montag
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function athlete(): array
    {
        $user  = User::factory()->create(['onboarding_completed_at' => now()]);
        $event = Event::create(['user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 25]);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'is_active' => true, 'name' => 'P', 'sessions' => '[]']);

        return [$user, $event, $plan];
    }

    private function planned(User $user, TrainingPlan $plan, string $date, string $status, string $type = 'easy_run'): void
    {
        TrainingSession::create(['user_id' => $user->id, 'training_plan_id' => $plan->id, 'planned_date' => $date,
            'type' => $type, 'intensity' => 'easy', 'title' => 'x', 'description' => 'x', 'status' => $status]);
    }

    public function test_a_week_with_everything_done_is_celebrated(): void
    {
        [$user, , $plan] = $this->athlete();
        $this->planned($user, $plan, '2026-11-03', 'completed');
        $this->planned($user, $plan, '2026-11-05', 'completed', 'tempo_run');
        $this->planned($user, $plan, '2026-11-06', 'planned', 'rest');
        $this->planned($user, $plan, '2026-11-08', 'completed', 'long_run');
        Activity::create(['user_id' => $user->id, 'strava_id' => 1, 'name' => 'L', 'type' => 'Run',
            'distance' => 18000, 'moving_time' => 6000, 'elapsed_time' => 6000, 'start_date' => '2026-11-08 08:00:00']);

        $week = app(MomentService::class)->weekDone($user);

        $this->assertSame(3, $week['done']);
        $this->assertSame(3, $week['total']);
        $this->assertSame(18.0, $week['km']);
        $this->assertSame(1, $week['streak']);
        $this->assertSame('tempo_run', $week['days'][3]['type']);
        $this->assertNull($week['days'][4]['type']); // Ruhetag zählt nicht
        $this->assertSame('Grundlage', $week['next']['label']);
    }

    public function test_a_skipped_session_spoils_the_week(): void
    {
        [$user, , $plan] = $this->athlete();
        $this->planned($user, $plan, '2026-11-03', 'completed');
        $this->planned($user, $plan, '2026-11-05', 'skipped');

        $this->assertNull(app(MomentService::class)->weekDone($user));
    }

    public function test_the_week_comes_once(): void
    {
        [$user, , $plan] = $this->athlete();
        $this->planned($user, $plan, '2026-11-03', 'completed');
        $this->planned($user, $plan, '2026-11-05', 'completed');

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('weekDone.done', 2));
        $this->actingAs($user)->postJson('/moments/seen', ['kind' => 'week', 'week_start' => '2026-11-02'])->assertOk();
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('weekDone', null));
    }

    public function test_a_race_shows_its_time_against_the_target(): void
    {
        $user  = User::factory()->create(['onboarding_completed_at' => now()]);
        $event = Event::create(['user_id' => $user->id, 'name' => '10er', 'event_date' => '2026-11-08',
            'race_distance' => '10km', 'priority' => 'B', 'target_time_hours' => 0, 'target_time_minutes' => 42]);
        // Einlaufen am Renntag ist kein Rennen.
        Activity::create(['user_id' => $user->id, 'strava_id' => 1, 'name' => 'Einlaufen', 'type' => 'Run',
            'distance' => 2500, 'moving_time' => 900, 'elapsed_time' => 900, 'start_date' => '2026-11-08 09:00:00']);
        $run = Activity::create(['user_id' => $user->id, 'strava_id' => 2, 'name' => '10er', 'type' => 'Run',
            'distance' => 10050, 'moving_time' => 2480, 'elapsed_time' => 2490, 'start_date' => '2026-11-08 10:00:00']);

        $race = app(MomentService::class)->raceRun($user);

        $this->assertSame($run->id, $race['activity_id']);
        $this->assertSame(2490, $race['seconds']);
        $this->assertSame(2520, $race['target_seconds']);
        $this->assertSame('4:09', $race['pace']);

        $this->actingAs($user)->postJson('/moments/seen', ['kind' => 'race', 'event_id' => $event->id])->assertOk();
        $this->assertNull(app(MomentService::class)->raceRun($user->fresh()));
    }

    public function test_no_race_moment_without_the_run(): void
    {
        $user = User::factory()->create();
        Event::create(['user_id' => $user->id, 'name' => '10er', 'event_date' => '2026-11-08',
            'race_distance' => '10km', 'priority' => 'B', 'target_time_hours' => 0, 'target_time_minutes' => 42]);

        $this->assertNull(app(MomentService::class)->raceRun($user));
    }

    public function test_someone_elses_race_cannot_be_marked(): void
    {
        $other = User::factory()->create();
        $event = Event::create(['user_id' => $other->id, 'name' => '10er', 'event_date' => '2026-11-08',
            'race_distance' => '10km', 'priority' => 'B', 'target_time_hours' => 0, 'target_time_minutes' => 42]);

        $this->actingAs(User::factory()->create(['onboarding_completed_at' => now()]))
            ->postJson('/moments/seen', ['kind' => 'race', 'event_id' => $event->id])->assertNotFound();
    }
}
