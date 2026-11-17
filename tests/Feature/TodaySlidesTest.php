<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Slider auf Heute (Jan, 17.11.2026): oben alle Einheiten von heute, egal ob
 * Plan oder nicht, unten die letzten fünf Aktivitäten, egal wie alt.
 */
class TodaySlidesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 20:00:00');
        CarbonImmutable::setTestNow('2026-11-17 20:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function activity(User $user, string $type, string $start, string $name, float $meters = 8000): Activity
    {
        static $id = 7000;

        return Activity::create([
            'user_id' => $user->id, 'strava_id' => ++$id, 'name' => $name, 'type' => $type,
            'distance' => $meters, 'moving_time' => 2700, 'elapsed_time' => 2700,
            'average_speed' => $meters / 2700, 'start_date' => $start,
        ]);
    }

    public function test_the_hero_gets_every_session_and_unplanned_activity_of_today(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        $ride = $this->activity($user, 'VirtualRide', '2026-11-17 11:04:00', 'Zwift - Rad locker', 22700);
        $bike = TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => '2026-11-17', 'type' => 'cross_training', 'sport_type' => 'VirtualRide',
            'title' => 'Rad locker', 'intensity' => 'low', 'status' => 'completed', 'duration_min' => 45,
            'activity_id' => $ride->id,
        ]);
        $strength = TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => '2026-11-17', 'type' => 'strength', 'title' => 'Kraft', 'intensity' => 'low',
            'status' => 'planned', 'duration_min' => 30, 'sort_order' => 1,
        ]);
        $run = $this->activity($user, 'Run', '2026-11-17 18:03:00', 'Hannover Laufen', 8400);
        $this->activity($user, 'Walk', '2026-11-17 14:00:00', 'Hannover Gehen', 2300);
        $this->activity($user, 'Run', '2026-11-16 18:00:00', 'Gestern');

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('todayPlanSession.id', $bike->id)
                ->where('todayPlanSession.measured', true)
                ->where('todayPlanSession.distance_km', 22.7)
                ->where('todayMore', fn ($more) => collect($more)->pluck('title')->all() === ['Kraft', 'Hannover Laufen'])
                ->where('todayMore.0.id', $strength->id)
                ->where('todayMore.1.activity_id', $run->id)
                ->where('todayMore.1.status', 'completed')
                ->where('todayMore.1.type', 'easy_run')
                ->where('todayMore.1.distance_km', 8.4)
                ->where('todayMore.1.duration_min', 45));
    }

    public function test_without_a_plan_today_activities_still_join_the_hero(): void
    {
        $user = User::factory()->onboarded()->create();
        $this->activity($user, 'Swim', '2026-11-17 08:10:00', 'Schwimmbad', 1475);

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('todayMore.0.title', 'Schwimmbad')
                ->where('todayMore.0.type', 'cross_training')
                ->where('todayMore.0.sport_type', 'Swim')
                ->where('todayMore.0.distance_km', null));
    }

    public function test_the_last_five_activities_regardless_of_age(): void
    {
        $user = User::factory()->onboarded()->create();
        $this->activity($user, 'Run', '2026-09-01 08:00:00', 'Ganz alt');
        foreach (['2026-10-01', '2026-10-05', '2026-10-10', '2026-10-20', '2026-11-17'] as $i => $d) {
            $this->activity($user, $i % 2 ? 'Ride' : 'Run', "{$d} 08:00:00", "Einheit {$i}");
        }
        $this->activity($user, 'Walk', '2026-11-16 08:00:00', 'Gehen');

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('lastActivities', fn ($list) => collect($list)->pluck('name')->all() === ['Einheit 4', 'Einheit 3', 'Einheit 2', 'Einheit 1', 'Einheit 0'])
                ->where('lastActivities.0.day', 'Heute')
                ->where('lastActivities.1.day', 'Di 20.10.'));
    }
}
