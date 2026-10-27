<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Registrieren nur per Einladung (Entscheidung von Jan, 27.10.2026):
 * ein Link für jeden, der ihn hat, einmal einlösbar, 14 Tage gültig.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $extra);
    }

    public function test_registration_screen_without_invite_shows_no_form(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Register')
                ->where('invite', null)
                ->where('inviteGiven', false));
    }

    public function test_registration_screen_with_valid_invite_offers_the_form(): void
    {
        $invite = Invitation::issue(null);

        $this->get('/register?invite='.$invite->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('invite', $invite->token));
    }

    public function test_new_users_can_register_with_an_invite(): void
    {
        $invite = Invitation::issue(null);

        $response = $this->post('/register', $this->payload(['invite' => $invite->token]));

        $this->assertAuthenticated();
        $response->assertRedirect(route('onboarding', absolute: false));

        $invite->refresh();
        $this->assertNotNull($invite->used_at);
        $this->assertSame(User::where('email', 'test@example.com')->value('id'), $invite->used_by);
    }

    public function test_registration_without_invite_is_rejected(): void
    {
        $this->post('/register', $this->payload())->assertSessionHasErrors('invite');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_invite_works_only_once(): void
    {
        $invite = Invitation::issue(null);

        $this->post('/register', $this->payload(['invite' => $invite->token]));
        $this->post('/logout');

        $this->post('/register', $this->payload(['invite' => $invite->token, 'email' => 'zweiter@example.com']))
            ->assertSessionHasErrors('invite');

        $this->assertDatabaseMissing('users', ['email' => 'zweiter@example.com']);
        $this->get('/register?invite='.$invite->token)
            ->assertInertia(fn (Assert $page) => $page->where('invite', null)->where('inviteGiven', true));
    }

    public function test_an_invite_expires_after_fourteen_days(): void
    {
        $invite = Invitation::issue(null);

        $this->travel(Invitation::VALID_DAYS)->days();
        $this->travel(1)->minutes();

        $this->post('/register', $this->payload(['invite' => $invite->token]))
            ->assertSessionHasErrors('invite');
        $this->assertGuest();
    }

    public function test_a_revoked_invite_cannot_be_used(): void
    {
        $invite = Invitation::issue(null);
        $invite->update(['revoked_at' => now()]);

        $this->post('/register', $this->payload(['invite' => $invite->token]))
            ->assertSessionHasErrors('invite');
        $this->assertGuest();
    }

    public function test_a_failed_registration_does_not_burn_the_invite(): void
    {
        $invite = Invitation::issue(null);

        $this->post('/register', $this->payload(['invite' => $invite->token, 'password_confirmation' => 'falsch']))
            ->assertSessionHasErrors('password');

        $this->assertNull($invite->fresh()->used_at);
    }
}
