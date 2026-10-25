<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\RunnerProfile;
use App\Models\User;
use App\Services\Vdot;
use App\Services\VdotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der VDOT und woher er kommt.
 *
 * Entscheidung vom 25.10.2026: Schwellenpace und die Bestzeiten der letzten
 * sechs Wochen werden je in einen VDOT umgerechnet, der höhere gilt.
 */
class VdotTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 880000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function profile(?float $threshold): RunnerProfile
    {
        $user = User::factory()->create();

        return RunnerProfile::create(['user_id' => $user->id, 'threshold_speed' => $threshold]);
    }

    private function effort(RunnerProfile $p, string $key, int $seconds, int $daysAgo): void
    {
        $activity = Activity::create([
            'user_id' => $p->user_id, 'strava_id' => $this->seq++, 'name' => 'Lauf', 'type' => 'Run',
            'distance' => 10000, 'moving_time' => 3000, 'elapsed_time' => 3000, 'start_date' => now()->subDays($daysAgo),
        ]);

        BestEffort::create([
            'user_id' => $p->user_id, 'activity_id' => $activity->id, 'distance_key' => $key,
            'distance_m' => \App\Services\BestEffortService::DISTANCES[$key], 'elapsed_time' => $seconds,
            'achieved_at' => now()->subDays($daysAgo),
        ]);
    }

    // ── Die Formel ───────────────────────────────────────────────────────

    /** T ist das Tempo bei 88 % — hin und zurück kommt dieselbe Pace heraus. */
    public function test_threshold_round_trips(): void
    {
        $vdot = Vdot::fromThresholdPace(270);

        $this->assertEqualsWithDelta(270, Vdot::paceAt($vdot, Vdot::THRESHOLD), 0.01);
    }

    /** Daniels' Tabelle: 10 km in 41:21 ist VDOT 50. */
    public function test_a_performance_gives_the_table_value(): void
    {
        $this->assertEqualsWithDelta(50.0, Vdot::fromPerformance(10000, 41 * 60 + 21), 0.1);
    }

    public function test_the_zones_are_ordered_and_contiguous(): void
    {
        $zones = $this->profile(4.5)->pace_zones;

        $this->assertSame(['z1', 'z2', 'z3', 'z4', 'z5'], array_keys($zones));
        $this->assertSame('∞', $zones['z1']['max_pace']);
        $this->assertSame('0:00', $zones['z5']['min_pace']);

        // Die schnelle Grenze einer Zone ist die langsame der nächsten.
        foreach ([1, 2, 3, 4] as $z) {
            $this->assertSame($zones['z' . ($z + 1)]['max_pace'], $zones["z{$z}"]['min_pace']);
        }
    }

    // ── Die Quelle ───────────────────────────────────────────────────────

    public function test_without_efforts_the_threshold_counts(): void
    {
        $info = app(VdotService::class)->forProfile($this->profile(4.5));

        $this->assertSame('threshold', $info['source']);
        $this->assertStringContainsString('Schwellenpace 4:30', $info['label']);
    }

    /** Eine Bestzeit belegt Form nach oben — ist sie besser, gilt sie. */
    public function test_a_faster_best_effort_wins(): void
    {
        $p = $this->profile(4.5);                 // VDOT ~44,6
        $this->effort($p, '10k', 41 * 60 + 21, 10); // VDOT 50

        $info = app(VdotService::class)->forProfile($p);

        $this->assertSame('best_effort', $info['source']);
        $this->assertEqualsWithDelta(50.0, $info['value'], 0.1);
        $this->assertSame('aus 10 km in 41:21 am 15.10.', $info['label']);
    }

    /** Eine langsame Teilstrecke aus einem lockeren Lauf drückt nichts nach unten. */
    public function test_a_slower_effort_does_not_lower_the_vdot(): void
    {
        $p = $this->profile(4.5);
        $this->effort($p, '5k', 30 * 60, 3);

        $this->assertSame('threshold', app(VdotService::class)->forProfile($p)['source']);
    }

    public function test_old_efforts_and_the_kilometre_do_not_count(): void
    {
        $p = $this->profile(4.5);
        $this->effort($p, '10k', 38 * 60, VdotService::EFFORT_WINDOW_DAYS + 1);
        $this->effort($p, '1k', 3 * 60, 2);

        $this->assertSame('threshold', app(VdotService::class)->forProfile($p)['source']);
    }

    /** Ohne Schwellenpace reicht eine Bestzeit für Zonen und Prognose. */
    public function test_a_best_effort_alone_is_enough(): void
    {
        $p = $this->profile(null);
        $this->effort($p, 'half', 90 * 60, 20);

        $this->assertNotNull($p->vdot());
        $this->assertNotEmpty($p->pace_zones);
    }

    public function test_nothing_known_means_no_vdot(): void
    {
        $p = $this->profile(null);

        $this->assertNull($p->vdot());
        $this->assertSame([], $p->calculatePaceZones());
    }
}
