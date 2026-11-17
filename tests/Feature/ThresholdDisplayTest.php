<?php

namespace Tests\Feature;

use App\Models\GarminDailyMetric;
use App\Models\RunnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Eine Schwelle statt drei (Usability-Befund N10, Jan 03.11.2026): angezeigt
 * wird die Schwelle aus dem VDOT, mit Herkunft.
 */
class ThresholdDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function profile(): RunnerProfile
    {
        $user = User::factory()->onboarded()->create();

        return RunnerProfile::create([
            'user_id'              => $user->id,
            'threshold_speed'      => 4 + 35 / 60,   // 4:35 gemessen
            'threshold_heart_rate' => 170,
            'max_heart_rate'       => 188,
        ]);
    }

    public function test_the_measured_threshold_shows_without_a_second_number(): void
    {
        $t = $this->profile()->thresholdDisplay();

        $this->assertSame('4:35', $t['pace']);
        $this->assertSame('threshold', $t['source']);
        $this->assertNull($t['measured'], 'Gleiche Zahl — nicht doppelt nennen');
    }

    /** Jan, 17.11.2026: die gemessene Schwelle gilt, die VO2max steht nur daneben. */
    public function test_a_higher_garmin_vo2max_does_not_replace_the_measured_threshold(): void
    {
        $profile = $this->profile();
        GarminDailyMetric::create(['user_id' => $profile->user_id, 'date' => '2026-11-03', 'vo2max' => 55]);

        $t = $profile->fresh()->thresholdDisplay();

        $this->assertSame('4:35', $t['pace']);
        $this->assertSame('threshold', $t['source']);
        $this->assertNull($t['measured']);

        $sources = collect($t['sources'])->keyBy('key');
        $this->assertTrue($sources['threshold']['used']);
        $this->assertFalse($sources['garmin']['used']);
        $this->assertNotNull($sources['garmin']['note']);
        $this->assertLessThan('4:35', $sources['garmin']['pace']);
    }

    public function test_without_a_measured_threshold_the_vo2max_sets_it_and_says_so(): void
    {
        $user    = User::factory()->onboarded()->create();
        $profile = RunnerProfile::create(['user_id' => $user->id]);
        GarminDailyMetric::create(['user_id' => $user->id, 'date' => '2026-11-03', 'vo2max' => 55]);

        $t = $profile->fresh()->thresholdDisplay();

        $this->assertSame('garmin', $t['source']);
        $this->assertStringContainsString('Garmin', $t['label']);
        $this->assertTrue($t['sources'][0]['used']);
    }

    public function test_the_more_page_shows_the_vdot_threshold(): void
    {
        $profile = $this->profile();
        $this->effort($profile, 17 * 60);
        $expected = $profile->fresh()->thresholdDisplay()['pace'];

        $this->assertNotSame('4:35', $expected, 'Die Bestzeit hebt die Schwelle an');
        $this->actingAs(User::find($profile->user_id))->get(route('more'))
            ->assertInertia(fn ($page) => $page->where('summary.threshold', $expected));
    }

    private function effort(RunnerProfile $p, int $seconds): void
    {
        $activity = \App\Models\Activity::create([
            'user_id' => $p->user_id, 'strava_id' => 991, 'name' => 'Lauf', 'type' => 'Run',
            'distance' => 5000, 'moving_time' => $seconds, 'elapsed_time' => $seconds, 'start_date' => now()->subDays(3),
        ]);

        \App\Models\BestEffort::create([
            'user_id' => $p->user_id, 'activity_id' => $activity->id, 'distance_key' => '5k',
            'distance_m' => 5000, 'elapsed_time' => $seconds, 'achieved_at' => now()->subDays(3),
        ]);
    }
}
