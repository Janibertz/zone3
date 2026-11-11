<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * „Freunde einladen“: jeder Athlet hat einen eigenen Link für wenige Freunde.
 */
class ReferralInviteTest extends TestCase
{
    use RefreshDatabase;

    private function register(Invitation $invite, string $name, string $email)
    {
        $response = $this->post('/register', [
            'invite' => $invite->token, 'name' => $name, 'email' => $email,
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        ]);
        auth()->logout();

        return $response;
    }

    public function test_an_athlete_gets_one_personal_link(): void
    {
        $jan = User::factory()->create(['name' => 'Jan Anders', 'onboarding_completed_at' => now()]);

        $this->actingAs($jan)->get('/mehr/einladen')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('More/Invite')
                ->where('invite.max', Invitation::REFERRAL_USES)
                ->where('invite.used', 0)
                ->whereNot('invite.url', null));
        $this->actingAs($jan)->get('/mehr/einladen');

        $invite = Invitation::sole();
        $this->assertSame($jan->id, $invite->created_by);
        $this->assertSame(Invitation::REFERRAL_SOURCE, $invite->source);
    }

    public function test_friends_land_with_the_source_and_the_link_fills_up(): void
    {
        $jan = User::factory()->create(['onboarding_completed_at' => now()]);
        $invite = Invitation::referralFor($jan);

        foreach (range(1, Invitation::REFERRAL_USES) as $n) {
            $this->register($invite, "Freund{$n} Läufer", "f{$n}@example.com")->assertRedirect();
        }
        $this->register($invite, 'Zu Spät', 'late@example.com')->assertSessionHasErrors('invite');

        $friend = User::where('email', 'f1@example.com')->sole();
        $this->assertSame('empfehlung', $friend->signup_source);
        $this->assertSame($invite->id, $friend->invitation_id);
        $this->assertNull($friend->waitlisted_at);

        // Voll bleibt voll — kein neuer Link hinter der Grenze.
        $this->assertSame($invite->id, Invitation::referralFor($jan)->id);
        $this->actingAs($jan)->get('/mehr/einladen')
            ->assertInertia(fn (Assert $p) => $p->where('invite.url', null)
                ->where('invite.status', 'used')
                ->where('invite.friends.0', 'Freund1'));
    }

    public function test_an_expired_link_is_replaced_and_a_revoked_one_is_not(): void
    {
        $jan = User::factory()->create(['onboarding_completed_at' => now()]);
        $old = Invitation::referralFor($jan);
        $old->update(['expires_at' => now()->subDay()]);

        $new = Invitation::referralFor($jan);
        $this->assertNotSame($old->id, $new->id);

        $new->update(['revoked_at' => now()]);
        $this->assertSame($new->id, Invitation::referralFor($jan)->id);
    }
}
