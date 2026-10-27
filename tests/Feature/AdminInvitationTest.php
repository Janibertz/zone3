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
        $invite->update(['used_at' => now(), 'used_by' => $user->id]);

        $this->actingAs($this->admin())->post("/admin/invitations/{$invite->id}/revoke");

        $this->assertNull($invite->fresh()->revoked_at);
        $this->assertSame('used', $invite->fresh()->status());
    }
}
