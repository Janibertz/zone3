<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaceTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['onboarding_completed_at' => now()]);
    }

    public function test_strategy_splits_for_10k_are_even_per_km(): void
    {
        $user  = $this->user();
        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Stadtlauf',
            'event_date'          => now()->addDays(5),
            'race_distance'       => '10km',
            'target_time_hours'   => 0,
            'target_time_minutes' => 50,
        ]);

        $res = $this->actingAs($user)->getJson(route('events.plan.strategy', $event->id));

        $res->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('pace', '5:00');

        $splits = $res->json('splits');
        $this->assertCount(10, $splits);                       // 1..9 km + finish
        $this->assertSame('10 km', $splits[9]['label']);
        $this->assertTrue($splits[9]['is_finish']);
        $this->assertSame('50:00', $splits[9]['cumulative_time']);
        $this->assertSame('25:00', $splits[4]['cumulative_time']); // 5 km halfway
    }

    public function test_strategy_splits_for_marathon_step_5k(): void
    {
        $user  = $this->user();
        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'City Marathon',
            'event_date'          => now()->addDays(3),
            'race_distance'       => 'marathon',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);

        $res = $this->actingAs($user)->getJson(route('events.plan.strategy', $event->id));

        $splits = $res->json('splits');
        $this->assertCount(9, $splits);                        // 5,10,...,40 + finish
        $this->assertSame('40 km', $splits[7]['label']);
        $this->assertSame('42.2 km', $splits[8]['label']);
        $this->assertTrue($splits[8]['is_finish']);
        $this->assertSame('3:30:00', $splits[8]['cumulative_time']);
    }

    public function test_analysis_matches_closest_distance_activity(): void
    {
        $user  = $this->user();
        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Herbstlauf',
            'event_date'          => now()->subDays(2),
            'race_distance'       => '10km',
            'target_time_hours'   => 0,
            'target_time_minutes' => 50,
        ]);

        // A short shakeout and the actual race on the same day — race = closest to 10k
        Activity::create([
            'user_id' => $user->id, 'strava_id' => 7001, 'name' => 'Shakeout', 'type' => 'Run',
            'distance' => 3000, 'moving_time' => 1080, 'elapsed_time' => 1080, 'start_date' => $event->event_date,
        ]);
        $race = Activity::create([
            'user_id' => $user->id, 'strava_id' => 7002, 'name' => 'Rennen', 'type' => 'Run',
            'distance' => 10000, 'moving_time' => 2940, 'elapsed_time' => 2940, 'start_date' => $event->event_date,
        ]);

        // Pre-cache analysis so no OpenAI call happens; matching must pick $race.
        TrainingPlan::create([
            'user_id'                   => $user->id,
            'event_id'                  => $event->id,
            'sessions'                  => [],
            'race_analysis_text'        => 'Starke Leistung.',
            'race_analysis_activity_id' => $race->id,
        ]);

        $res = $this->actingAs($user)->getJson(route('events.plan.analysis', $event->id));

        $res->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('analysis_text', 'Starke Leistung.')
            ->assertJsonPath('race_activity_id', $race->id)
            ->assertJsonPath('actual_time', '49:00'); // 2940s
    }

    public function test_analysis_not_found_without_race_activity(): void
    {
        $user  = $this->user();
        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Altes Rennen',
            'event_date'          => now()->subDays(2),
            'race_distance'       => '10km',
            'target_time_hours'   => 0,
            'target_time_minutes' => 50,
        ]);
        TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        $this->actingAs($user)
            ->getJson(route('events.plan.analysis', $event->id))
            ->assertOk()
            ->assertJsonPath('found', false);
    }
}
