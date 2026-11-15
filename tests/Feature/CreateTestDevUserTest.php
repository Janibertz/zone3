<?php

namespace Tests\Feature;

use App\Console\Commands\CreateTestDevUser;
use App\Models\Activity;
use App\Models\TrainingSession;
use App\Models\User;
use Database\Seeders\CoachSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Das Konto der Browser-Tests (tests/e2e) — getrennt vom Demo-Konto für Apple. */
class CreateTestDevUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_test_account_with_plan_runs_and_a_coach_message(): void
    {
        $this->seed(CoachSeeder::class);

        $this->artisan('testdev:create')->assertSuccessful();
        // Ein zweiter Lauf setzt es neu auf, statt abzubrechen.
        $this->artisan('testdev:create')->assertSuccessful();

        $user = User::where('email', CreateTestDevUser::EMAIL)->sole();
        $this->assertSame('Testdev Läufer', $user->name);
        $this->assertNotNull($user->ai_coach_consent_at);
        $this->assertSame('Testdev-Halbmarathon', $user->events()->sole()->name);
        $this->assertSame(14, TrainingSession::where('user_id', $user->id)->count());
        $this->assertSame(3, Activity::where('user_id', $user->id)->count());
        $this->assertSame(1, \App\Models\CoachMessage::where('user_id', $user->id)->count());
        $this->assertFalse(User::where('email', 'demo@zone3.run')->exists());
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('testdev:create')->assertFailed();
        $this->assertFalse(User::where('email', CreateTestDevUser::EMAIL)->exists());
    }
}
