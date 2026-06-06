<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\User;
use App\Services\WrappedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WrappedTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 5000;

    private function addRun(User $u, string $datetime, int $meters, int $sec, int $elev): Activity
    {
        return Activity::create([
            'user_id' => $u->id, 'strava_id' => $this->seq++, 'name' => 'Lauf', 'type' => 'Run',
            'distance' => $meters, 'moving_time' => $sec, 'elapsed_time' => $sec,
            'total_elevation_gain' => $elev, 'average_speed' => $meters / $sec,
            'start_date' => $datetime,
        ]);
    }

    public function test_year_wrapped_aggregates_correctly(): void
    {
        $user = User::factory()->create();

        // 2025 runs (Mon/Tue/Wed consecutive + a second Monday)
        $a = $this->addRun($user, '2025-03-03 08:00:00', 10000, 3000, 50);  // longest
        $this->addRun($user, '2025-03-04 08:00:00', 5000, 1500, 20);
        $this->addRun($user, '2025-03-05 08:00:00', 8000, 2000, 100);        // fastest (4.0 m/s → 4:10)
        $this->addRun($user, '2025-03-10 08:00:00', 6000, 1800, 30);         // 2nd Monday

        // Previous year for the comparison (14.5 km)
        $this->addRun($user, '2024-06-01 08:00:00', 14500, 4000, 0);

        // A PR achieved in 2025
        BestEffort::create([
            'user_id' => $user->id, 'activity_id' => $a->id, 'distance_key' => '5k',
            'distance_m' => 5000, 'elapsed_time' => 1200, 'achieved_at' => '2025-04-01 08:00:00',
        ]);

        $s = app(WrappedService::class)->generate($user, 'year', 2025);

        $this->assertTrue($s['has_data']);
        $this->assertSame('2025', $s['period_label']);
        $this->assertSame(4, $s['totals']['runs']);
        $this->assertSame(29.0, $s['totals']['km']);
        $this->assertSame(2.3, $s['totals']['hours']);
        $this->assertSame(200, $s['totals']['elevation']);
        $this->assertSame(4, $s['totals']['active_days']);

        $this->assertSame(10.0, $s['longest_run']['km']);
        $this->assertSame('4:10', $s['fastest_run']['pace']);

        $this->assertSame(2, $s['favorite_weekday']['count']); // two Mondays
        $this->assertSame(3, $s['longest_streak']);            // 03-03, 03-04, 03-05

        $this->assertSame(1, $s['prs']['count']);
        $this->assertContains('5 km', $s['prs']['distances']);

        $this->assertSame(14.5, $s['vs_previous']['prev_km']);
        $this->assertSame(100, $s['vs_previous']['delta_pct']);
    }

    public function test_period_without_runs_has_no_data(): void
    {
        $user = User::factory()->create();
        $this->addRun($user, '2025-03-03 08:00:00', 10000, 3000, 50);

        $s = app(WrappedService::class)->generate($user, 'year', 2023);
        $this->assertFalse($s['has_data']);
    }
}
