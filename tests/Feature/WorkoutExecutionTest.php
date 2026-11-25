<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\SessionRecommendation;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Run\WorkoutExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Soll und Ist je Block mit Umsetzungswert und das Höhenprofil im
 * Coach-Vorschlag (Mockup „Zone3 Einheiten mit Profil und Soll/Ist“,
 * Jan 25.11.2026). Feste Regeln, kein Modellaufruf.
 */
class WorkoutExecutionTest extends TestCase
{
    use RefreshDatabase;

    /** 15 min ein, 3 × 1000 m in 4:00–4:10 mit 2 min Trab, 10 min aus. */
    private function steps(): array
    {
        return [
            ['type' => 'warmup', 'label' => 'Einlaufen', 'duration_min' => 15, 'pace_target' => '5:20–5:50', 'zone' => 2],
            ['type' => 'work', 'label' => '1000 m', 'duration_min' => 4.1, 'distance_m' => 1000, 'pace_target' => '4:00–4:10', 'zone' => 5, 'repetitions' => 3],
            ['type' => 'rest', 'label' => 'Trab', 'duration_min' => 2, 'pace_target' => null, 'zone' => 1, 'repetitions' => 2],
            ['type' => 'cooldown', 'label' => 'Auslaufen', 'duration_min' => 10, 'pace_target' => '5:20–5:50', 'zone' => 2],
        ];
    }

    private function lap(float $meters, float $seconds, int $hr = 150): array
    {
        return ['distance' => $meters, 'moving_time' => $seconds, 'elapsed_time' => $seconds, 'average_speed' => $meters / $seconds, 'average_heartrate' => $hr];
    }

    private function activity(User $user, array $laps): Activity
    {
        return Activity::create([
            'user_id' => $user->id, 'source' => Activity::SOURCE_GARMIN, 'name' => 'Intervalle', 'type' => 'Run',
            'distance' => 9000, 'moving_time' => 2700, 'elapsed_time' => 2700, 'average_speed' => 3.3,
            'start_date' => now()->subHour(), 'laps' => $laps,
        ]);
    }

    private function intervalLaps(float $third = 245): array
    {
        return [
            $this->lap(2800, 900, 135),   // Einlaufen 5:21
            $this->lap(1000, 245, 172),   // 4:05
            $this->lap(350, 120, 150),
            $this->lap(1000, 248, 175),   // 4:08
            $this->lap(350, 120, 152),
            $this->lap(1000, $third, 178),
            $this->lap(1850, 600, 140),   // Auslaufen 5:24
            $this->lap(40, 12, 130),      // Stopp-Taste nach ein paar Schritten
        ];
    }

    public function test_blocks_in_range_score_full_marks(): void
    {
        $user = User::factory()->onboarded()->create();
        $out  = app(WorkoutExecution::class)->compare($this->steps(), $this->activity($user, $this->intervalLaps()));

        $this->assertNotNull($out);
        $this->assertSame(100, $out['score']);
        $this->assertCount(3, $out['blocks']);
        $this->assertSame('Alle drei Blöcke geschafft.', $out['sentence']);
        $this->assertSame('Ein- und Auslaufen locker genug.', $out['easy']);
        // Profile: sieben Abschnitte geplant und gelaufen, die Schlussrunde fällt weg.
        $this->assertCount(7, $out['planned']);
        $this->assertCount(7, $out['actual']);
        $this->assertSame('4:05', $out['blocks'][0]['actual']);
        $this->assertSame(172, $out['blocks'][0]['hr']);
    }

    public function test_a_block_too_fast_costs_points_and_is_named(): void
    {
        $user = User::factory()->onboarded()->create();
        // Block 3 in 3:53 — 7 s schneller als der Bereich.
        $out = app(WorkoutExecution::class)->compare($this->steps(), $this->activity($user, $this->intervalLaps(233)));

        $this->assertSame(44, $out['blocks'][2]['score']);
        $this->assertSame('fast', $out['blocks'][2]['status']);
        $this->assertSame('Zwei von drei Blöcken geschafft. Block 3 war im Schnitt 7 s zu schnell.', $out['sentence']);
        $this->assertLessThan(100, $out['score']);
    }

    public function test_laps_that_do_not_match_the_plan_give_no_comparison(): void
    {
        $user = User::factory()->onboarded()->create();
        // Autolap je Kilometer statt Runden je Abschnitt.
        $laps = array_fill(0, 9, $this->lap(1000, 300));

        $this->assertNull(app(WorkoutExecution::class)->compare($this->steps(), $this->activity($user, $laps)));
    }

    public function test_a_single_step_session_is_compared_with_the_whole_run(): void
    {
        $user = User::factory()->onboarded()->create();
        $steps = [['type' => 'work', 'label' => 'Locker', 'duration_min' => 45, 'pace_target' => '5:20–5:50', 'zone' => 2]];
        $run = Activity::create([
            'user_id' => $user->id, 'source' => Activity::SOURCE_GARMIN, 'name' => 'Locker', 'type' => 'Run',
            'distance' => 8000, 'moving_time' => 2700, 'elapsed_time' => 2760, 'average_speed' => 8000 / 2700,
            'start_date' => now()->subHour(),
        ]);

        $out = app(WorkoutExecution::class)->compare($steps, $run);

        $this->assertSame(100, $out['score']);
        $this->assertSame('Genau wie geplant gelaufen.', $out['sentence']);
    }

    public function test_a_ride_gets_no_comparison(): void
    {
        $user = User::factory()->onboarded()->create();
        $ride = $this->activity($user, $this->intervalLaps());
        $ride->update(['type' => 'VirtualRide']);

        $this->assertNull(app(WorkoutExecution::class)->compare($this->steps(), $ride));
    }

    public function test_the_activity_page_shows_the_comparison_for_a_planned_session(): void
    {
        $user = User::factory()->onboarded()->create();
        $run  = $this->activity($user, $this->intervalLaps());
        TrainingSession::create([
            'user_id' => $user->id, 'planned_date' => now()->toDateString(), 'type' => 'interval',
            'title' => '3 × 1000 m', 'intensity' => 'high', 'status' => 'completed', 'activity_id' => $run->id, 'steps' => $this->steps(),
        ]);

        $this->actingAs($user)->get(route('activities.show', $run))
            ->assertInertia(fn ($page) => $page->where('execution.score', 100)->where('execution.blocks.0.n', 1));
    }

    // ── Coach-Vorschlag: vorher/neu ─────────────────────────────────────

    public function test_the_preview_shows_the_steps_accepting_writes_without_saving(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Zielrennen', 'event_date' => now()->addDays(40),
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();
        $session = TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => now()->toDateString(), 'type' => 'interval', 'title' => 'Intervalle',
            'distance_km' => 10, 'duration_min' => 60, 'zone' => 5, 'intensity' => 'high',
            'status' => 'planned', 'steps' => $this->steps(),
        ]);
        $rec = SessionRecommendation::create([
            'user_id' => $user->id, 'training_session_id' => $session->id,
            'source' => SessionRecommendation::SOURCE_WELLBEING, 'reason' => 'HRV niedrig.',
            'before' => ['type' => 'interval', 'title' => 'Intervalle'],
            'after'  => ['type' => 'easy_run', 'title' => 'Locker', 'duration_min' => 40, 'distance_km' => 7, 'zone' => 2, 'intensity' => 'low'],
        ]);

        $preview = $rec->previewSteps();

        $this->assertNotEmpty($preview);
        $this->assertSame('interval', $session->fresh()->type, 'Die Vorschau speichert nichts.');
        $this->assertEquals($this->steps(), $session->fresh()->steps);

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('coachRecommendation.before_steps', $this->steps())
                ->where('coachRecommendation.after_steps', $preview));

        $rec->accept();
        $this->assertEquals($preview, $session->fresh()->steps, 'Angenommen gilt genau der gezeigte Ablauf.');
    }
}
