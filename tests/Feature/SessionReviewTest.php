<?php

namespace Tests\Feature;

use App\Models\RunnerProfile;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionReviewTest extends TestCase
{
    use RefreshDatabase;

    private function completedSessionWithQuestion(User $user, array $overrides = []): int
    {
        return DB::table('training_sessions')->insertGetId(array_merge([
            'user_id'         => $user->id,
            'planned_date'    => now()->toDateString(),
            'type'            => 'long_run',
            'title'           => 'Long Run mit MP',
            'description'     => 'Test',
            'intensity'       => 'medium',
            'status'          => 'completed',
            'sort_order'      => 0,
            'coach_review'    => 'Dein Puls lag heute höher als sonst.',
            'review_question' => 'Lag das am Schlaf oder am Wetter?',
            'review_options'  => json_encode(['Schlaf', 'Wetter', 'Alles normal']),
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides));
    }

    public function test_review_feedback_is_saved_and_remembered_in_coach_notes(): void
    {
        $user = User::factory()->create();
        RunnerProfile::create(['user_id' => $user->id, 'coach_notes' => null]);
        $sessionId = $this->completedSessionWithQuestion($user);

        $this->actingAs($user)
            ->patchJson(route('training-sessions.review-feedback', $sessionId), [
                'feedback' => 'Schlaf',
            ])
            ->assertOk();

        $session = TrainingSession::find($sessionId);
        $this->assertSame('Schlaf', $session->review_feedback);

        $notes = $user->fresh()->runnerProfile->coach_notes;
        $this->assertNotNull($notes);
        $this->assertStringContainsString('Schlaf', $notes);
        $this->assertStringContainsString('Lag das am Schlaf oder am Wetter?', $notes);
    }

    public function test_feedback_rejected_when_session_has_no_review_question(): void
    {
        $user = User::factory()->create();
        RunnerProfile::create(['user_id' => $user->id]);
        $sessionId = $this->completedSessionWithQuestion($user, [
            'review_question' => null,
            'review_options'  => null,
        ]);

        $this->actingAs($user)
            ->patchJson(route('training-sessions.review-feedback', $sessionId), [
                'feedback' => 'Schlaf',
            ])
            ->assertStatus(422);
    }

    public function test_feedback_forbidden_for_other_users_session(): void
    {
        $owner     = User::factory()->create();
        $stranger  = User::factory()->create();
        $sessionId = $this->completedSessionWithQuestion($owner);

        $this->actingAs($stranger)
            ->patchJson(route('training-sessions.review-feedback', $sessionId), [
                'feedback' => 'Wetter',
            ])
            ->assertStatus(403);
    }
}
