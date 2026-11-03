<?php

namespace Tests\Feature;

use App\Models\StravaAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Strava nur nach Freischaltung (Jan, 03.11.2026): Strava hat keine freien
 * Plätze, die Werbung holt Garmin-Läufer. Ein Admin schaltet es pro Athlet
 * frei; wer schon verbunden ist, behält es.
 */
class StravaGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.strava.client_id' => 'test-client', 'services.strava.redirect' => 'https://zone3.test/strava/callback']);
    }

    public function test_a_new_athlete_does_not_see_strava(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->assertFalse($user->canUseStrava());
        $this->actingAs($user)->get(route('more'))
            ->assertInertia(fn ($page) => $page->where('auth.strava', false));
    }

    public function test_connecting_without_unlock_leads_to_the_connections(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->get(route('strava.connect'))
            ->assertRedirect(route('profile.edit', ['section' => 'connections']));
    }

    public function test_an_unlocked_athlete_can_connect(): void
    {
        $user = User::factory()->onboarded()->create(['strava_allowed' => true]);

        $response = $this->actingAs($user)->get(route('strava.connect'));

        $this->assertStringStartsWith('https://www.strava.com/oauth/authorize', $response->headers->get('Location'));
    }

    public function test_a_connected_athlete_keeps_strava(): void
    {
        $user = User::factory()->onboarded()->create();
        StravaAccount::create(['user_id' => $user->id, 'strava_id' => 1, 'access_token' => 'a', 'refresh_token' => 'r', 'token_expires_at' => now()->addDay()]);

        $this->assertTrue($user->fresh()->canUseStrava());
    }

    public function test_an_admin_unlocks_and_hides_strava(): void
    {
        $admin = User::factory()->onboarded()->create(['is_admin' => true]);
        $user  = User::factory()->onboarded()->create();

        $this->actingAs($admin)->patch(route('admin.users.toggle-strava', $user))->assertRedirect();
        $this->assertTrue($user->fresh()->strava_allowed);

        $this->actingAs($admin)->patch(route('admin.users.toggle-strava', $user))->assertRedirect();
        $this->assertFalse($user->fresh()->strava_allowed);
    }

    public function test_an_athlete_cannot_unlock_themselves(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->patch(route('admin.users.toggle-strava', $user))->assertForbidden();
        $this->assertFalse($user->fresh()->strava_allowed);
    }
}
