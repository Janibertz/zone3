<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Verschieben per Ziehen, Loeschen per Wischen (Jan, 28.10.2026).
 * Regeln in SessionScheduleService.
 */
class SessionGestureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 08:00:00');
        Queue::fake();

        $this->user = User::factory()->onboarded()->create();
        $event = Event::create([
            'user_id' => $this->user->id, 'name' => 'HM', 'event_date' => '2026-12-06',
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ]);
        $this->plan = TrainingPlan::create(['user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function plannedSession(array $attrs = []): TrainingSession
    {
        return TrainingSession::create($attrs + [
            'user_id' => $this->user->id, 'training_plan_id' => $this->plan->id, 'event_id' => $this->plan->event_id,
            'planned_date' => '2026-10-29', 'type' => 'interval', 'title' => '5×1000 m', 'description' => '',
            'intensity' => 'high', 'status' => 'planned', 'sort_order' => 0,
            'steps' => [['type' => 'work', 'distance_m' => 1000, 'repetitions' => 5]],
        ]);
    }

    private function onDay(string $date)
    {
        return TrainingSession::where('user_id', $this->user->id)->whereDate('planned_date', $date)->orderBy('sort_order')->get();
    }

    public function test_dragging_onto_a_rest_day_replaces_the_rest_and_seals_the_old_day(): void
    {
        $run = $this->plannedSession();
        $this->plannedSession(['planned_date' => '2026-10-31', 'type' => 'rest', 'title' => 'Ruhetag', 'intensity' => 'rest', 'steps' => null]);

        $this->actingAs($this->user)
            ->patchJson(route('training-sessions.move', $run), ['date' => '2026-10-31'])
            ->assertOk();

        $run->refresh();
        $this->assertSame('2026-10-31', $run->planned_date->toDateString());
        $this->assertNotNull($run->pinned_at);
        $this->assertCount(1, $run->steps, 'Die Struktur bleibt beim Verschieben.');
        $this->assertSame(['5×1000 m'], $this->onDay('2026-10-31')->pluck('title')->all());

        // Kein Loch, wo die Einheit stand.
        $this->assertSame(['rest'], $this->onDay('2026-10-29')->pluck('type')->all());
    }

    public function test_dragging_onto_a_run_keeps_both(): void
    {
        $run = $this->plannedSession();
        $this->plannedSession(['planned_date' => '2026-10-30', 'type' => 'easy_run', 'title' => 'Locker', 'intensity' => 'low']);

        $this->actingAs($this->user)
            ->patchJson(route('training-sessions.move', $run), ['date' => '2026-10-30'])
            ->assertOk();

        // Der Athlet darf bewusst zweimal laufen; das Gezogene kommt dahinter.
        $this->assertSame(['Locker', '5×1000 m'], $this->onDay('2026-10-30')->pluck('title')->all());
    }

    public function test_rest_days_races_and_finished_sessions_stay_put(): void
    {
        $rest = $this->plannedSession(['type' => 'rest', 'title' => 'Ruhetag', 'intensity' => 'rest']);
        $race = $this->plannedSession(['type' => 'race_prep', 'title' => 'Rennen']);
        $done = $this->plannedSession(['status' => 'completed']);

        foreach ([$rest, $race, $done] as $s) {
            $this->actingAs($this->user)->patchJson(route('training-sessions.move', $s), ['date' => '2026-10-30'])->assertStatus(422);
            $this->actingAs($this->user)->deleteJson(route('training-sessions.destroy', $s))->assertStatus(422);
        }
    }

    public function test_nothing_moves_into_the_past(): void
    {
        $this->actingAs($this->user)
            ->patchJson(route('training-sessions.move', $this->plannedSession()), ['date' => '2026-10-27'])
            ->assertStatus(422);
    }

    public function test_deleting_a_plan_session_leaves_a_rest_day(): void
    {
        $run = $this->plannedSession();

        $this->actingAs($this->user)->deleteJson(route('training-sessions.destroy', $run))->assertOk();

        $this->assertNull($run->fresh());
        $this->assertSame(['rest'], $this->onDay('2026-10-29')->pluck('type')->all());
    }

    public function test_deleting_a_scheduled_workout_leaves_nothing_behind(): void
    {
        $extra = $this->plannedSession(['training_plan_id' => null, 'event_id' => null, 'planned_date' => '2026-11-10']);

        $this->actingAs($this->user)->deleteJson(route('training-sessions.destroy', $extra))->assertOk();

        // Vor dem Planstart haette ein Ruhetag hier nichts zu suchen.
        $this->assertCount(0, $this->onDay('2026-11-10'));
    }

    public function test_someone_elses_session_is_refused(): void
    {
        $other = User::factory()->onboarded()->create();
        $run   = $this->plannedSession();

        $this->actingAs($other)->patchJson(route('training-sessions.move', $run), ['date' => '2026-10-30'])->assertForbidden();
        $this->actingAs($other)->deleteJson(route('training-sessions.destroy', $run))->assertForbidden();
    }
}
