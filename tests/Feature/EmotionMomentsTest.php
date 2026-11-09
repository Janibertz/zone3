<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\BestEffortService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Die Momente mit Foto (09.11.2026): „Neue Bestzeit“ auf Heute und der
 * Fortschritt zum Rennen auf der Rennen-Seite.
 */
class EmotionMomentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeRun(User $user, int $id, string $date): Activity
    {
        return Activity::create([
            'user_id' => $user->id, 'strava_id' => $id, 'name' => "Lauf {$id}", 'type' => 'Run',
            'distance' => 10000, 'moving_time' => 2500, 'elapsed_time' => 2500, 'start_date' => $date,
        ]);
    }

    private function effort(User $user, Activity $run, string $key, int $sec): void
    {
        BestEffort::create([
            'user_id' => $user->id, 'activity_id' => $run->id, 'distance_key' => $key,
            'distance_m' => BestEffortService::DISTANCES[$key], 'elapsed_time' => $sec,
            'achieved_at' => $run->start_date,
        ]);
    }

    public function test_the_longest_beaten_record_is_celebrated(): void
    {
        $user = User::factory()->create();
        $old  = $this->makeRun($user, 1, '2026-09-20 08:00:00');
        $this->effort($user, $old, '5k', 1250);
        $this->effort($user, $old, '10k', 2550);

        $new = $this->makeRun($user, 2, '2026-11-08 09:00:00');
        $this->effort($user, $new, '5k', 1240);
        $this->effort($user, $new, '10k', 2512);

        $record = app(BestEffortService::class)->celebration($user->id, $new->id);

        $this->assertSame('10 km', $record['distance']);
        $this->assertSame(2512, $record['seconds']);
        $this->assertSame(2550, $record['previous_seconds']);
        $this->assertSame('4:11', $record['pace']);
    }

    public function test_a_first_time_is_not_a_record(): void
    {
        $user = User::factory()->create();
        $run  = $this->makeRun($user, 1, '2026-11-08 09:00:00');
        $this->effort($user, $run, '10k', 2512);

        $this->assertNull(app(BestEffortService::class)->celebration($user->id, $run->id));
    }

    public function test_a_later_faster_run_does_not_count_as_the_previous_best(): void
    {
        $user = User::factory()->create();
        $old  = $this->makeRun($user, 1, '2026-09-20 08:00:00');
        $this->effort($user, $old, '10k', 2550);
        $pr   = $this->makeRun($user, 2, '2026-10-20 08:00:00');
        $this->effort($user, $pr, '10k', 2512);
        // Danach noch schneller — der Vergleich für den Lauf vom 20.10. bleibt 42:30.
        $later = $this->makeRun($user, 3, '2026-11-08 08:00:00');
        $this->effort($user, $later, '10k', 2490);

        $this->assertSame(2550, app(BestEffortService::class)->celebration($user->id, $pr->id)['previous_seconds']);
    }

    public function test_the_dashboard_shows_the_record_with_the_next_race(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $old  = $this->makeRun($user, 1, '2026-09-20 08:00:00');
        $this->effort($user, $old, '10k', 2550);
        $new  = $this->makeRun($user, 2, '2026-11-08 09:00:00');
        $this->effort($user, $new, '10k', 2512);
        RunnerProfile::create(['user_id' => $user->id, 'pending_pr_activity_id' => $new->id, 'pending_pr_message' => 'Stark.']);
        Event::create(['user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 25]);

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('personalRecord.seconds', 2512)
            ->where('personalRecord.race.days', 153));
    }

    public function test_the_race_page_shows_how_far_the_plan_is(): void
    {
        $user  = User::factory()->create(['onboarding_completed_at' => now()]);
        $event = Event::create(['user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11',
            'plan_start_date' => '2026-10-19', 'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 25]);
        TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'is_active' => true, 'name' => 'Plan', 'sessions' => '[]']);

        // 19.10. bis 11.04. = 174 Tage = 25 Wochen; heute (09.11.) ist Tag 21 → Woche 4.
        $this->actingAs($user)->get('/events')->assertInertia(fn (Assert $page) => $page
            ->where('events.0.progress.week', 4)
            ->where('events.0.progress.weeks', 25)
            ->where('events.0.progress.percent', 12)
            ->where('events.0.progress.phase', 'Grundlage'));
    }

    public function test_without_a_plan_there_is_no_progress(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        Event::create(['user_id' => $user->id, 'name' => '10er', 'event_date' => '2027-03-01',
            'race_distance' => '10km', 'priority' => 'B', 'target_time_hours' => 0, 'target_time_minutes' => 42]);

        $this->actingAs($user)->get('/events')->assertInertia(fn (Assert $page) => $page
            ->where('events.0.progress', null));
    }
}
