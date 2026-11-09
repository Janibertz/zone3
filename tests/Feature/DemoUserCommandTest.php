<?php

namespace Tests\Feature;

use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Demo-Konto für die App-Prüfung (09.11.2026). */
class DemoUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_approved_account_with_a_plan(): void
    {
        $this->artisan('demo:create', ['email' => 'demo@zone3.run', '--password' => 'geheim-12345'])->assertSuccessful();

        $user = User::where('email', 'demo@zone3.run')->first();
        $this->assertNull($user->waitlisted_at);
        $this->assertNotNull($user->onboarding_completed_at);
        $this->assertTrue(Hash::check('geheim-12345', $user->password));
        $this->assertSame(14, TrainingSession::where('user_id', $user->id)->count());
        $this->assertNotEmpty(TrainingSession::where('user_id', $user->id)->where('type', 'tempo_run')->first()->steps);

        $this->post('/login', ['email' => 'demo@zone3.run', 'password' => 'geheim-12345']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_it_never_touches_an_existing_account_without_reset_or_an_admin(): void
    {
        User::factory()->create(['email' => 'jan@zone3.run', 'is_admin' => true]);

        $this->artisan('demo:create', ['email' => 'jan@zone3.run'])->assertFailed();
        $this->artisan('demo:create', ['email' => 'jan@zone3.run', '--reset' => true])->assertFailed();
    }
}
