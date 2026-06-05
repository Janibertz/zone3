<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\User;
use App\Services\BestEffortService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BestEffortTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(User $user, int $stravaId, string $date): Activity
    {
        return Activity::create([
            'user_id'      => $user->id,
            'strava_id'    => $stravaId,
            'name'         => "Run {$stravaId}",
            'type'         => 'Run',
            'distance'     => 8000,
            'moving_time'  => 2400,
            'elapsed_time' => 2400,
            'start_date'   => $date,
        ]);
    }

    private function detail(int $fiveK, int $oneK): array
    {
        return ['best_efforts' => [
            ['name' => '1k', 'distance' => 1000, 'elapsed_time' => $oneK],
            ['name' => '5k', 'distance' => 5000, 'elapsed_time' => $fiveK],
            // an untracked distance that must be ignored
            ['name' => '2 mile', 'distance' => 3219, 'elapsed_time' => 900],
        ]];
    }

    public function test_extracts_only_tracked_distances(): void
    {
        $user = User::factory()->create();
        $run  = $this->makeRun($user, 1001, '2026-05-01 08:00:00');

        $new = app(BestEffortService::class)->syncFromActivityData($run, $this->detail(1500, 270));

        // First-ever efforts are not "new records" (no previous reference)
        $this->assertSame([], $new);
        // Only 1k + 5k stored — the 2-mile effort is ignored
        $this->assertSame(2, BestEffort::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('best_efforts', [
            'activity_id' => $run->id, 'distance_key' => '5k', 'elapsed_time' => 1500,
        ]);
        $this->assertNotNull($run->fresh()->best_efforts_synced_at);
    }

    public function test_detects_new_record_and_ranks_top_three(): void
    {
        $user    = User::factory()->create();
        $service = app(BestEffortService::class);

        $run1 = $this->makeRun($user, 2001, '2026-05-01 08:00:00');
        $service->syncFromActivityData($run1, $this->detail(1500, 270)); // 5k 25:00, 1k 4:30

        $run2 = $this->makeRun($user, 2002, '2026-05-10 08:00:00');
        // 5k faster (new PR), 1k slower (no PR)
        $new = $service->syncFromActivityData($run2, $this->detail(1450, 280));

        $this->assertSame(['5k'], $new);

        $top = collect($service->topThree($user->id))->keyBy('key');
        $fiveK = $top['5k']['entries'];
        $this->assertSame('24:10', $fiveK[0]['time_formatted']); // run2 fastest
        $this->assertSame('25:00', $fiveK[1]['time_formatted']); // run1 second
        $this->assertSame($run2->id, $fiveK[0]['activity_id']);

        $oneK = $top['1k']['entries'];
        $this->assertSame('4:30', $oneK[0]['time_formatted']); // run1 still the record
    }

    public function test_resync_is_idempotent_and_not_a_new_record(): void
    {
        $user    = User::factory()->create();
        $service = app(BestEffortService::class);

        $run = $this->makeRun($user, 3001, '2026-05-01 08:00:00');
        $service->syncFromActivityData($run, $this->detail(1500, 270));

        // Re-syncing the same activity must not duplicate rows nor flag a record
        $new = $service->syncFromActivityData($run, $this->detail(1500, 270));

        $this->assertSame([], $new);
        $this->assertSame(2, BestEffort::where('activity_id', $run->id)->count());
    }
}
