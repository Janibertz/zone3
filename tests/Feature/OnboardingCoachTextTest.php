<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OnboardingTexts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Coach-Sprechblasen im Onboarding und der Schritt „Benachrichtigungen“
 * (Jan, 07.11.2026). Die Texte pflegt der Admin.
 */
class OnboardingCoachTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_gets_a_text_for_every_step_with_a_bubble(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);

        $this->actingAs($user)->get(route('onboarding'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('coachTexts.notify', OnboardingTexts::DEFAULTS['notify']['text'])
                ->has('coachTexts', count(OnboardingTexts::DEFAULTS))
                ->has('telegram.available'));
    }

    public function test_an_admin_can_change_a_text_and_an_empty_field_means_default(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'onboarding_completed_at' => now()]);

        $this->actingAs($admin)->put(route('admin.onboarding-texts.update'), [
            'texts' => ['goal' => 'Was ist dein Ziel, {name}?', 'bike' => ''],
        ])->assertRedirect();

        $texts = OnboardingTexts::all();
        $this->assertSame('Was ist dein Ziel, {name}?', $texts['goal']);
        $this->assertSame(OnboardingTexts::DEFAULTS['bike']['text'], $texts['bike']);
    }

    public function test_an_athlete_cannot_change_texts(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $this->actingAs($user)->put(route('admin.onboarding-texts.update'), ['texts' => ['goal' => 'x']])
            ->assertForbidden();
    }

    public function test_push_and_telegram_work_before_onboarding_is_complete(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);

        $this->actingAs($user)->postJson(route('push.subscribe'), [
            'endpoint' => 'https://push.example/abc', 'public_key' => 'k', 'auth_token' => 'a',
        ])->assertSuccessful();

        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $user->id]);
    }
}
