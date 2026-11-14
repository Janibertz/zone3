<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiagnoseEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'diag-0123456789abcdef0123456789abcdef';

    public function test_without_a_configured_token_the_endpoint_does_not_exist(): void
    {
        config(['services.diagnose.token' => null]);
        $this->getJson('/diagnose/system', ['Authorization' => 'Bearer ' . self::TOKEN])->assertNotFound();
    }

    public function test_a_wrong_or_missing_token_gets_a_404(): void
    {
        config(['services.diagnose.token' => self::TOKEN]);
        $this->getJson('/diagnose/system')->assertNotFound();
        $this->getJson('/diagnose/system', ['Authorization' => 'Bearer falsch'])->assertNotFound();
    }

    public function test_a_short_token_is_never_accepted(): void
    {
        config(['services.diagnose.token' => 'kurz']);
        $this->getJson('/diagnose/system', ['Authorization' => 'Bearer kurz'])->assertNotFound();
    }

    public function test_the_reports_answer_and_leak_no_email(): void
    {
        config(['services.diagnose.token' => self::TOKEN]);
        $user = User::factory()->onboarded()->create(['name' => 'Erika Muster', 'email' => 'erika@example.com']);
        $h = ['Authorization' => 'Bearer ' . self::TOKEN];

        $this->getJson('/diagnose/system', $h)->assertOk()->assertJsonStructure(['summary', 'queues', 'live_activities']);
        $this->getJson('/diagnose/logs?q=x', $h)->assertOk();

        $users = $this->getJson('/diagnose/users', $h)->assertOk();
        $users->assertJsonFragment(['first_name' => 'Erika']);
        $this->assertStringNotContainsString('erika@example.com', $users->getContent());
        $this->assertStringNotContainsString('Muster', $users->getContent());

        $one = $this->getJson("/diagnose/users/{$user->id}?day=2026-11-14", $h)->assertOk()
            ->assertJsonStructure(['profile', 'sessions', 'widget_items', 'live_activity' => ['state', 'start_at', 'rows'], 'coach_messages', 'ai_logs']);
        $this->assertStringNotContainsString('erika@example.com', $one->getContent());
    }
}
