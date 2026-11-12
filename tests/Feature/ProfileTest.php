<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->onboarded()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->onboarded()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->onboarded()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->onboarded()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->onboarded()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
    public function test_an_account_without_own_password_is_deleted_with_the_word(): void
    {
        // Mit Apple oder per Link angemeldet: kein Passwort, das man kennt.
        $user = User::factory()->onboarded()->create();
        $user->forceFill(['has_password' => false])->save();

        $this->actingAs($user)->from('/profile')
            ->delete('/profile', ['confirm' => 'falsch'])
            ->assertSessionHasErrors('confirm');
        $this->assertNotNull($user->fresh());

        $this->actingAs($user)
            ->delete('/profile', ['confirm' => 'löschen'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_an_account_with_password_cannot_be_deleted_with_the_word(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->from('/profile')
            ->delete('/profile', ['confirm' => 'LÖSCHEN'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($user->fresh());
    }

    public function test_a_passwordless_signup_has_no_own_password(): void
    {
        $user = app(\App\Services\Auth\Access::class)->register('neu@example.com', 'Neu');

        $this->assertFalse($user->fresh()->has_password);
    }
}
