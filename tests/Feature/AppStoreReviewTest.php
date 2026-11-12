<?php

namespace Tests\Feature;

use App\Models\Coach;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Was die App-Prüfung verlangt (12.11.2026): Einwilligung in die
 * KI-Übermittlung (Guideline 5.1.2(i)) und Melden geteilter Inhalte (1.2).
 */
class AppStoreReviewTest extends TestCase
{
    use RefreshDatabase;

    private function sharedWorkout(User $owner): Workout
    {
        return Workout::create([
            'user_id' => $owner->id, 'name' => 'Pyramide', 'type' => 'interval',
            'is_public' => true, 'published_at' => now(),
            'blocks' => [['type' => 'work', 'label' => 'Schnell', 'duration_mode' => 'time', 'duration_sec' => 300, 'pace_zone' => 5]],
        ]);
    }

    public function test_choosing_a_coach_requires_the_ai_consent(): void
    {
        $user  = User::factory()->create(['onboarding_completed_at' => null]);
        $coach = Coach::first() ?? Coach::forceCreate([
            'name' => 'Max', 'slug' => 'max-test', 'specialty' => 'companion', 'tagline' => 'Los.',
            'description' => 'Begleiter.', 'avatar_color' => 'blue', 'avatar_initials' => 'M', 'personality_prompt' => 'Freundlich.',
        ]);

        $this->actingAs($user)->postJson(route('onboarding.coach'), ['coach_id' => $coach->id])
            ->assertStatus(422)->assertJsonValidationErrors('ai_consent');
        $this->assertNull($user->fresh()->ai_coach_consent_at);

        $this->actingAs($user)->postJson(route('onboarding.coach'), ['coach_id' => $coach->id, 'ai_consent' => true])
            ->assertOk();
        $this->assertNotNull($user->fresh()->ai_coach_consent_at);
    }

    public function test_an_older_account_is_asked_once(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->get('/mehr')
            ->assertInertia(fn ($page) => $page->where('auth.aiConsent', false));

        $this->actingAs($user)->post(route('ai-consent.store'), ['ai_consent' => false])->assertSessionHasErrors('ai_consent');
        $this->actingAs($user)->post(route('ai-consent.store'), ['ai_consent' => true])->assertRedirect();

        $this->actingAs($user)->get('/mehr')
            ->assertInertia(fn ($page) => $page->where('auth.aiConsent', true));
    }

    public function test_a_reported_workout_disappears_for_the_reporter_only(): void
    {
        $owner    = User::factory()->onboarded()->create();
        $reporter = User::factory()->onboarded()->create();
        $other    = User::factory()->onboarded()->create();
        $workout  = $this->sharedWorkout($owner);

        $this->actingAs($reporter)->postJson(route('workouts.report', $workout), ['reason' => 'Beleidigender Titel'])->assertOk();
        // Zweimal melden legt keine zweite Meldung an.
        $this->actingAs($reporter)->postJson(route('workouts.report', $workout))->assertOk();
        $this->assertSame(1, WorkoutReport::count());

        $this->actingAs($reporter)->getJson(route('workouts.shared'))->assertJsonMissing(['name' => 'Pyramide']);
        $this->actingAs($other)->getJson(route('workouts.shared'))->assertJsonFragment(['name' => 'Pyramide']);
        $this->assertTrue($workout->fresh()->is_public);
    }

    public function test_nobody_reports_their_own_or_a_private_workout(): void
    {
        $owner   = User::factory()->onboarded()->create();
        $workout = $this->sharedWorkout($owner);

        $this->actingAs($owner)->postJson(route('workouts.report', $workout))->assertNotFound();

        $workout->update(['is_public' => false]);
        $this->actingAs(User::factory()->onboarded()->create())
            ->postJson(route('workouts.report', $workout))->assertNotFound();
    }
}
