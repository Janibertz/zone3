<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Redesign Paket 3 (28.10.2026): „Mehr" ist eine Seite, und die Rennen
 * zeigen am nächsten Rennen Ziel, Prognose und Pace.
 */
class MorePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 12:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_more_shows_what_is_set_up(): void
    {
        $user = User::factory()->onboarded()->create();
        RunnerProfile::create(['user_id' => $user->id, 'threshold_speed' => 4.2, 'training_model' => 'polarized']);
        Event::create(['user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11', 'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 29]);

        $this->actingAs($user)->get(route('more'))
            ->assertInertia(fn ($page) => $page
                ->component('More')
                ->where('summary.events', 1)
                ->where('summary.threshold', '4:12')
                ->where('summary.model', '80/20')
                ->where('summary.strava', false)
                ->where('summary.telegram', false));
    }

    public function test_races_carry_target_prediction_and_pace_in_seconds(): void
    {
        $user = User::factory()->onboarded()->create();
        RunnerProfile::create(['user_id' => $user->id, 'threshold_speed' => 4.2]);
        Event::create(['user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11', 'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 29]);

        $this->actingAs($user)->get(route('events.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Events/Index')
                ->where('events.0.target_seconds', 89 * 60)
                ->where('events.0.target_pace', '4:13')
                ->where('events.0.predicted_seconds', fn ($s) => $s > 4000 && $s < 7000)
                ->where('events.0.result_seconds', null));
    }
}
