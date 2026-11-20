<?php

namespace Tests\Feature;

use App\Models\RouvyConnection;
use App\Models\RouvyWebhookEvent;
use App\Models\User;
use App\Services\Admin\AdminInsights;
use App\Services\Admin\AdminPushService;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ROUVY steht im Admin wie jede andere Anbindung. */
class AdminRouvyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->onboarded()->create(['name' => 'Jan Test']);
        RouvyConnection::create([
            'user_id' => $this->user->id, 'rouvy_user_id' => 'abc123', 'access_token' => 'tok',
            'refresh_token' => 'ref', 'expires_at' => now()->addHour(), 'ai_consent_at' => now(),
        ]);
    }

    public function test_rouvy_is_listed_as_provider_and_on_the_athlete(): void
    {
        $insights = app(AdminInsights::class);

        $row = collect($insights->providers())->firstWhere('key', 'rouvy');
        $this->assertNotNull($row);
        $this->assertSame(1, $row['count']);

        $this->assertContains('ROUVY', collect($insights->connections($this->user))->pluck('name')->all());
        $this->assertContains('ROUVY', $insights->deviceNames($this->user));
    }

    public function test_rouvy_is_a_push_group(): void
    {
        $this->assertArrayHasKey('rouvy', AdminPushService::GROUPS);
        $this->assertSame([$this->user->id], app(AdminPushService::class)->audience('group', 'rouvy')->pluck('id')->all());
    }

    public function test_rouvy_webhook_calls_resolve_the_athlete(): void
    {
        RouvyWebhookEvent::create(['type' => 'activity', 'owner_id' => 'abc123', 'outcome' => 'queued']);

        $hooks = app(SystemHealth::class)->providerWebhooks();

        $this->assertSame(1, $hooks['rouvy']['total']);
        $this->assertSame('Jan Test', $hooks['rouvy']['recent'][0]['user']);
        $this->assertSame('in der Queue', $hooks['rouvy']['recent'][0]['label']);
    }

    public function test_admin_pages_render_with_rouvy(): void
    {
        $admin = User::factory()->onboarded()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/system')->assertOk();
        $this->actingAs($admin)->get('/admin/users/' . $this->user->id)->assertOk();
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
    }
}
