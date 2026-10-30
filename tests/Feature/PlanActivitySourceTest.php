<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Planseite nennt die echte Quelle einer Aktivität.
 *
 * Gemeldet am 30.10.2026: ein Spaziergang vom Garmin-Armband stand im Plan als
 * „Nicht im Plan · aus Strava“ — der Text war fest im Code, aus der Zeit, als
 * es nur Strava gab.
 */
class PlanActivitySourceTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private function unplannedSession(User $user, array $activity): void
    {
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => now()->addMonths(5),
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 25,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        $act = Activity::create($activity + [
            'user_id' => $user->id, 'name' => 'Gehen', 'type' => 'Walk',
            'start_date' => now(), 'distance' => 300, 'moving_time' => 240, 'elapsed_time' => 240,
        ]);

        TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => now()->toDateString(), 'type' => 'cross_training', 'sport_type' => 'Walk',
            'title' => 'Gehen', 'description' => '', 'intensity' => 'low', 'status' => 'completed', 'was_unplanned' => true, 'activity_id' => $act->id,
        ]);

        $this->event = $event;
    }

    public function test_a_garmin_activity_is_labelled_garmin(): void
    {
        $user = User::factory()->onboarded()->create();
        $this->unplannedSession($user, ['garmin_activity_id' => 123456, 'source' => Activity::SOURCE_GARMIN]);

        $this->actingAs($user)->get(route('events.plan.show', $this->event))
            ->assertInertia(fn ($page) => $page->where('sessions.0.activity_source', 'Garmin'));
    }

    public function test_a_strava_activity_is_labelled_strava(): void
    {
        $user = User::factory()->onboarded()->create();
        $this->unplannedSession($user, ['strava_id' => 987654, 'source' => Activity::SOURCE_STRAVA]);

        $this->actingAs($user)->get(route('events.plan.show', $this->event))
            ->assertInertia(fn ($page) => $page->where('sessions.0.activity_source', 'Strava'));
    }
}
