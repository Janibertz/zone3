<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\Carbon;
use App\Models\WellbeingEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Vor dem Planstart gibt es den Tagesvorschlag (09.11.2026, Jan: Plan ab
 * 30.11., bis dahin keine Vorschläge).
 */
class BridgeToPlanStartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function athlete(?string $planStart): array
    {
        $user  = User::factory()->create(['onboarding_completed_at' => now()]);
        $event = Event::create(['user_id' => $user->id, 'name' => 'HM Hannover', 'event_date' => '2027-04-11',
            'plan_start_date' => $planStart, 'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 25]);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'is_active' => true, 'name' => 'P', 'sessions' => '[]']);

        return [$user, $plan];
    }

    public function test_before_the_plan_starts_the_daily_suggestion_bridges(): void
    {
        [$user] = $this->athlete('2026-11-30');
        // Angenommener Vorschlag: ohne Plan, für heute.
        TrainingSession::create(['user_id' => $user->id, 'planned_date' => '2026-11-09', 'type' => 'easy_run',
            'intensity' => 'low', 'title' => 'Locker', 'description' => 'x', 'status' => 'planned']);

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('bridgeToPlan.days', 21)
            ->where('bridgeToPlan.race', 'HM Hannover')
            ->where('todayRecommendationSession.title', 'Locker'));
    }

    public function test_a_running_plan_does_not_bridge(): void
    {
        [$user] = $this->athlete(null);

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('bridgeToPlan', null));
    }

    public function test_a_plan_session_today_wins_over_the_suggestion(): void
    {
        [$user, $plan] = $this->athlete('2026-11-30');
        TrainingSession::create(['user_id' => $user->id, 'training_plan_id' => $plan->id, 'planned_date' => '2026-11-09',
            'type' => 'easy_run', 'intensity' => 'low', 'title' => 'Vom Coach', 'description' => 'x', 'status' => 'planned']);

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('bridgeToPlan', null)
            ->where('todayPlanSession.title', 'Vom Coach'));
    }

    public function test_the_suggestion_knows_when_the_plan_starts(): void
    {
        [$user] = $this->athlete('2026-11-30');
        WellbeingEntry::create(['user_id' => $user->id, 'date' => '2026-11-09', 'energy_level' => 7,
            'sleep_quality' => 7, 'muscle_soreness' => 3, 'stress_level' => 3, 'mood' => 7]);
        config(['services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"type":"easy_run","title":"Locker","description":"x","distance_km":6,"duration_min":35}'], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
        ], 200)]);

        $this->actingAs($user)->getJson('/api/ai/recommendation/today')->assertOk();

        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'PLANSTART: Der Trainingsplan für HM Hannover beginnt am Mo, 30. November'));
    }
}
