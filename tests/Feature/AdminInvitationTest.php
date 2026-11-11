<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Einladungen erzeugt nur ein Admin.
 */
class AdminInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_an_athlete_cannot_create_invitations(): void
    {
        $athlete = User::factory()->create(['is_admin' => false]);

        $this->actingAs($athlete)->get('/admin/invitations')->assertForbidden();
        $this->actingAs($athlete)->post('/admin/invitations')->assertForbidden();
        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_admin_creates_a_link_valid_for_fourteen_days(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/invitations', ['note' => 'für Christopher'])->assertRedirect();

        $invite = Invitation::sole();
        $this->assertSame('für Christopher', $invite->note);
        $this->assertSame($admin->id, $invite->created_by);
        $this->assertSame(40, strlen($invite->token));
        $this->assertTrue($invite->expires_at->between(now()->addDays(14)->subMinute(), now()->addDays(14)->addMinute()));

        $this->actingAs($admin)->get('/admin/invitations')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Invitations/Index')
                ->where('invitations.0.status', 'open')
                ->where('invitations.0.url', route('register', ['invite' => $invite->token])));
    }

    public function test_admin_can_revoke_an_open_link(): void
    {
        $invite = Invitation::issue(null);

        $this->actingAs($this->admin())->post("/admin/invitations/{$invite->id}/revoke")->assertRedirect();

        $this->assertSame('revoked', $invite->fresh()->status());
    }

    public function test_revoking_a_redeemed_link_changes_nothing(): void
    {
        $user = User::factory()->create();
        $invite = Invitation::issue(null);
        $invite->redeem($user);

        $this->actingAs($this->admin())->post("/admin/invitations/{$invite->id}/revoke");

        $this->assertNull($invite->fresh()->revoked_at);
        $this->assertSame('used', $invite->fresh()->status());
    }

    public function test_admin_creates_a_link_for_several_people(): void
    {
        $this->actingAs($this->admin())->post('/admin/invitations', [
            'note' => 'Freunde', 'multi' => true, 'max_uses' => 3, 'days' => 90, 'source' => 'Freunde',
        ])->assertRedirect();

        $invite = Invitation::sole();
        $this->assertSame(3, $invite->max_uses);
        $this->assertSame('freunde', $invite->source);
        $this->assertTrue($invite->expires_at->between(now()->addDays(90)->subMinute(), now()->addDays(90)->addMinute()));
    }

    public function test_a_link_for_several_people_admits_up_to_its_limit(): void
    {
        $invite = Invitation::issue(null, 'Freunde', 2, 90, 'freunde');

        foreach (['a', 'b'] as $n) {
            $this->post('/register', [
                'invite' => $invite->token, 'name' => "Läufer {$n}", 'email' => "{$n}@example.com",
                'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
            ])->assertRedirect();
            auth()->logout();
        }

        $this->post('/register', [
            'invite' => $invite->token, 'name' => 'Läufer c', 'email' => 'c@example.com',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        ])->assertSessionHasErrors('invite');

        $invite->refresh();
        $this->assertSame(2, $invite->uses_count);
        $this->assertSame('used', $invite->status());
        $this->assertSame(['freunde', 'freunde'], User::where('invitation_id', $invite->id)->pluck('signup_source')->all());
        $this->assertNull(User::where('email', 'c@example.com')->first());
    }

    public function test_a_link_without_limit_stays_open_and_can_be_revoked_after_use(): void
    {
        $invite = Invitation::issue(null, null, null, 30);
        $invite->redeem(User::factory()->create());
        $invite->redeem(User::factory()->create());

        $this->assertTrue($invite->fresh()->isUsable());

        $this->actingAs($this->admin())->post("/admin/invitations/{$invite->id}/revoke");
        $this->assertSame('revoked', $invite->fresh()->status());
        $this->assertSame(2, User::where('invitation_id', $invite->id)->count());
    }
}
