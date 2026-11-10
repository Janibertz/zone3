<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * „Als Nächstes“ auf Heute (10.11.2026): die folgenden Einheiten ohne
 * Ruhetage und ohne die, die oben schon als heutige steht.
 */
class UpcomingSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_lists_the_next_sessions_without_rest_and_today(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        $make = fn (string $date, string $type, string $title, string $status = 'planned') => TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => $date, 'type' => $type, 'title' => $title, 'intensity' => 'low', 'status' => $status,
            'distance_km' => 8, 'duration_min' => 45,
        ]);

        $today = $make('2026-11-10', 'easy_run', 'Heute locker');
        $make('2026-11-11', 'tempo_run', 'Schwelle');
        $make('2026-11-12', 'rest', 'Ruhetag');
        $make('2026-11-13', 'easy_run', 'Erledigt', 'completed');
        $make('2026-11-14', 'long_run', 'Lang');
        $make('2026-12-01', 'easy_run', 'Zu weit weg');

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('todayPlanSession.id', $today->id)
                ->where('upcomingSessions', fn ($list) => collect($list)->pluck('title')->all() === ['Schwelle', 'Lang'])
                ->where('upcomingSessions.0.href', "/events/{$event->id}/plan?open=".TrainingSession::where('title', 'Schwelle')->value('id')));
    }
}
