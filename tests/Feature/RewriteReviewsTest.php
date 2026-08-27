<?php

namespace Tests\Feature;

use App\Jobs\GenerateSessionReviewJob;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ein falsch geschriebenes Review laesst sich neu schreiben.
 *
 * Der Text entsteht einmal und bleibt stehen. Eine Migration repariert die
 * Einheit, nicht den bereits verfassten Text — ein Review, das eine
 * Schwimmeinheit als Lauf bespricht, bliebe sonst fuer immer stehen.
 */
class RewriteReviewsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->onboarded()->create();

        $event = Event::create([
            'user_id' => $this->user->id, 'name' => 'Zielrennen',
            'event_date' => now()->addDays(40), 'race_distance' => 'marathon',
            'priority' => 'A', 'target_time_hours' => 3, 'target_time_minutes' => 30,
        ]);
        $this->plan = TrainingPlan::create(['user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => []]);
    }

    private function reviewed(string $type, int $daysAgo = 1): TrainingSession
    {
        return TrainingSession::create([
            'user_id' => $this->user->id, 'training_plan_id' => $this->plan->id,
            'planned_date' => now()->subDays($daysAgo)->toDateString(),
            'type' => $type, 'sport_type' => $type === 'cross_training' ? 'Swim' : null,
            'title' => 'Einheit', 'description' => '', 'intensity' => 'medium',
            'status' => 'completed',
            'coach_review' => 'Dein Lauf war stark!', 'review_question' => 'Wie lief es?',
            'reviewed_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_the_old_text_is_discarded_and_regenerated(): void
    {
        $session = $this->reviewed('cross_training');

        $this->artisan('review:rewrite', ['--session' => [$session->id], '--yes' => true])->assertSuccessful();

        $session->refresh();
        $this->assertNull($session->coach_review, 'Der alte Text muss weg — sonst kehrt der Job sofort zurueck');
        $this->assertNull($session->reviewed_at);
        Queue::assertPushed(GenerateSessionReviewJob::class);
    }

    /** Nur Alternativtraining anfassen, wenn man das verlangt. */
    public function test_the_cross_filter_leaves_runs_alone(): void
    {
        $run  = $this->reviewed('easy_run');
        $swim = $this->reviewed('cross_training');

        $this->artisan('review:rewrite', ['--user' => $this->user->id, '--cross' => true, '--yes' => true])->assertSuccessful();

        $this->assertNotNull($run->refresh()->coach_review);
        $this->assertNull($swim->refresh()->coach_review);
    }

    /** Was ausserhalb des Zeitraums liegt, bleibt unberuehrt. */
    public function test_older_sessions_are_out_of_range(): void
    {
        $old = $this->reviewed('cross_training', daysAgo: 60);

        $this->artisan('review:rewrite', ['--user' => $this->user->id, '--days' => 30, '--yes' => true])->assertSuccessful();

        $this->assertNotNull($old->refresh()->coach_review);
        Queue::assertNotPushed(GenerateSessionReviewJob::class);
    }

    public function test_nothing_to_do_is_not_an_error(): void
    {
        $this->artisan('review:rewrite', ['--user' => $this->user->id, '--yes' => true])->assertSuccessful();

        Queue::assertNotPushed(GenerateSessionReviewJob::class);
    }
}
