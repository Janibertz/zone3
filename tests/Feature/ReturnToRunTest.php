<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\RunnerProfile;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\ReturnToRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnToRunTest extends TestCase
{
    use RefreshDatabase;

    private int $stravaSeq = 1000;

    private function addRun(User $user, int $daysAgo): void
    {
        Activity::create([
            'user_id'      => $user->id,
            'strava_id'    => $this->stravaSeq++,
            'name'         => "Run -{$daysAgo}",
            'type'         => 'Run',
            'distance'     => 8000,
            'moving_time'  => 2400,
            'elapsed_time' => 2400,
            'start_date'   => now()->subDays($daysAgo),
        ]);
    }

    private function rtrStatus(User $user): ?array
    {
        return app(ReturnToRunService::class)->statusFor($user->fresh());
    }

    public function test_gap_then_one_run_is_step_two_break(): void
    {
        $user = User::factory()->create();
        foreach ([40, 38, 36] as $d) $this->addRun($user, $d); // routine
        $this->addRun($user, 2);                                // first run back after a long gap

        $s = $this->rtrStatus($user);
        $this->assertNotNull($s);
        $this->assertSame('break', $s['trigger']);
        $this->assertSame(2, $s['step']);
        $this->assertFalse($s['pre_return']);
    }

    public function test_injury_while_paused_is_step_one_injured(): void
    {
        $user = User::factory()->create();
        foreach ([30, 28, 26] as $d) $this->addRun($user, $d); // routine, then paused (>7d ago)
        WellbeingEntry::create([
            'user_id' => $user->id, 'date' => now()->subDays(5)->toDateString(),
            'energy_level' => 5, 'mood' => 5, 'sleep_quality' => 5, 'muscle_soreness' => 5, 'stress_level' => 5,
            'is_injured' => true, 'is_sick' => false,
        ]);

        $s = $this->rtrStatus($user);
        $this->assertNotNull($s);
        $this->assertSame('injured', $s['trigger']);
        $this->assertSame(1, $s['step']);
        $this->assertTrue($s['pre_return']);
    }

    public function test_five_runs_since_return_is_complete(): void
    {
        $user = User::factory()->create();
        foreach ([40, 38, 36] as $d) $this->addRun($user, $d);   // routine
        foreach ([10, 8, 6, 4, 2] as $d) $this->addRun($user, $d); // 5 runs after the gap

        $this->assertNull($this->rtrStatus($user));
    }

    public function test_dismissal_hides_the_phase(): void
    {
        $user = User::factory()->create();
        RunnerProfile::create(['user_id' => $user->id, 'return_to_run_dismissed_at' => now()->subDay()]);
        foreach ([40, 38, 36] as $d) $this->addRun($user, $d);
        $this->addRun($user, 2);

        $this->assertNull($this->rtrStatus($user));
    }

    public function test_continuous_training_has_no_phase(): void
    {
        $user = User::factory()->create();
        foreach ([2, 5, 8, 11, 14, 17, 20] as $d) $this->addRun($user, $d); // every 3 days, no gap

        $this->assertNull($this->rtrStatus($user));
    }
}
