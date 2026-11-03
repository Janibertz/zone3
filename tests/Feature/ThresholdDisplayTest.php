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

    public function test_a_higher_garmin_vo2max_sets_the_threshold_and_says_so(): void
    {
        $profile = $this->profile();
        GarminDailyMetric::create(['user_id' => $profile->user_id, 'date' => '2026-11-03', 'vo2max' => 55]);

        $t = $profile->fresh()->thresholdDisplay();

        $this->assertSame('garmin', $t['source']);
        $this->assertStringContainsString('Garmin', $t['label']);
        $this->assertSame('4:35', $t['measured']);
        $this->assertLessThan('4:35', $t['pace'], 'Ein höherer VDOT heißt eine schnellere Schwelle');
    }

    public function test_the_more_page_shows_the_vdot_threshold(): void
    {
        $profile = $this->profile();
        GarminDailyMetric::create(['user_id' => $profile->user_id, 'date' => '2026-11-03', 'vo2max' => 55]);
        $expected = $profile->fresh()->thresholdDisplay()['pace'];

        $this->actingAs(User::find($profile->user_id))->get(route('more'))
            ->assertInertia(fn ($page) => $page->where('summary.threshold', $expected));
    }
}
