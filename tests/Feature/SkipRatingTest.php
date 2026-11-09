<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** „Nicht bewerten“ in „Wie lief's?“ (09.11.2026). */
class SkipRatingTest extends TestCase
{
    use RefreshDatabase;

    private function done(User $user, TrainingPlan $plan, string $date): TrainingSession
    {
        return TrainingSession::create(['user_id' => $user->id, 'training_plan_id' => $plan->id,
            'planned_date' => $date, 'type' => 'easy_run', 'intensity' => 'easy', 'title' => 'Locker',
            'description' => 'x', 'status' => 'completed']);
    }

    public function test_a_skipped_session_leaves_the_list_without_a_rating(): void
    {
        $user  = User::factory()->create(['onboarding_completed_at' => now()]);
        $event = Event::create(['user_id' => $user->id, 'name' => 'HM', 'event_date' => now()->addMonths(3)->toDateString(),
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 30]);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'is_active' => true, 'name' => 'P', 'sessions' => '[]']);
        $a = $this->done($user, $plan, now()->subDay()->toDateString());
        $b = $this->done($user, $plan, now()->subDays(2)->toDateString());

        $this->actingAs($user)->patchJson('/training-sessions/skip-rating', ['ids' => [$a->id]])->assertOk();

        $this->assertNull($a->fresh()->rating);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('unratedSessions', 1)
            ->where('unratedSessions.0.id', $b->id));
    }

    public function test_someone_else_cannot_skip_my_sessions(): void
    {
        $user  = User::factory()->create();
        $event = Event::create(['user_id' => $user->id, 'name' => 'HM', 'event_date' => now()->addMonths(3)->toDateString(),
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 30]);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'is_active' => true, 'name' => 'P', 'sessions' => '[]']);
        $mine  = $this->done($user, $plan, now()->subDay()->toDateString());

        $this->actingAs(User::factory()->create(['onboarding_completed_at' => now()]))->patchJson('/training-sessions/skip-rating', ['ids' => [$mine->id]])->assertOk();

        $this->assertNull($mine->fresh()->rating_skipped_at);
    }
}
