<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\RunnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test for the dashboard route.
 *
 * The dashboard assembles a lot of data inline; before this there was nothing
 * that would catch a fatal there. Renders once empty and once with activities,
 * so both the empty states and the populated path are exercised.
 */
class DashboardRenderTest extends TestCase
{
    use RefreshDatabase;

    private function runner(): User
    {
        return User::factory()->create([
            'onboarding_completed_at' => now(),
        ]);
    }

    public function test_dashboard_renders_for_a_user_without_data(): void
    {
        $this->actingAs($this->runner())
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_dashboard_renders_with_activities(): void
    {
        $user = $this->runner();

        RunnerProfile::create([
            'user_id'          => $user->id,
            'threshold_speed'  => 4.85,
        ]);

        foreach (range(0, 8) as $i) {
            Activity::create([
                'user_id'              => $user->id,
                'strava_id'            => 900000 + $i,
                'name'                 => 'Lauf ' . ($i + 1),
                'type'                 => 'Run',
                'distance'             => 6000 + $i * 900,
                'moving_time'          => 1800 + $i * 180,
                'elapsed_time'         => 1900 + $i * 180,
                'average_speed'        => 2.9 + ($i % 4) * 0.12,
                'average_heartrate'    => 150 + $i,
                'total_elevation_gain' => 30 + $i * 5,
                'start_date'           => now()->subDays($i),
            ]);
        }

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    /** The decrypted Garmin token must never reach the frontend. */
    public function test_dashboard_payload_does_not_leak_the_garmin_session(): void
    {
        $user = $this->runner();
        $user->forceFill([
            'garmin_email'   => 'runner@example.com',
            'garmin_session' => 'SECRET-GARMIN-TOKEN-XYZ',
        ])->save();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('SECRET-GARMIN-TOKEN-XYZ');
    }
}
