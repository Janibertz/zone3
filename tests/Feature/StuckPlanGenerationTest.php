<?php

namespace Tests\Feature;

use App\Jobs\GenerateEventTrainingPlanJob;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Der hängengebliebene Erstellungs-Schalter.
 *
 * `plan_generating` war ein Schalter ohne Zeitstempel, den nur derselbe Job
 * zurücksetzen konnte, der ihn gesetzt hatte. Starb der Job hart — ein
 * Deploy startet den Container neu, und `failed()` läuft bei einem SIGKILL
 * nicht — blieb er für immer stehen:
 *
 *   · Die Planseite zeigte dauerhaft "analysiert deine Daten".
 *   · Die Schaltfläche war wirkungslos, weil generate() bei gesetztem
 *     Schalter sofort zurückkehrt, ohne einen Job zu starten.
 *   · Und RegeneratePlanJob, das den Plan danach durchaus neu baute und
 *     eine Benachrichtigung schickte, fasste den Schalter nie an.
 */
class StuckPlanGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function event(User $user, array $attrs = []): Event
    {
        return Event::create(array_merge([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(40),
            'race_distance'       => '10km',
            'priority'            => 'A',
            'target_time_hours'   => 0,
            'target_time_minutes' => 45,
        ], $attrs));
    }

    // ── Erkennung ────────────────────────────────────────────────────────

    public function test_a_fresh_run_counts_as_running(): void
    {
        $event = $this->event(User::factory()->onboarded()->create(), [
            'plan_generating'    => true,
            'plan_generating_at' => now()->subMinutes(3),
        ]);

        $this->assertTrue($event->isGeneratingPlan());
        $this->assertFalse($event->hasStalePlanGeneration());
    }

    public function test_an_old_run_counts_as_stuck(): void
    {
        $event = $this->event(User::factory()->onboarded()->create(), [
            'plan_generating'    => true,
            'plan_generating_at' => now()->subMinutes(Event::PLAN_GENERATING_STALE_MINUTES + 1),
        ]);

        $this->assertFalse($event->isGeneratingPlan());
        $this->assertTrue($event->hasStalePlanGeneration());
    }

    /** Ein gesetzter Schalter ohne Zeitstempel stammt aus der Zeit davor. */
    public function test_a_flag_without_a_timestamp_counts_as_stuck(): void
    {
        $event = $this->event(User::factory()->onboarded()->create(), ['plan_generating' => true]);

        $this->assertFalse($event->isGeneratingPlan());
        $this->assertTrue($event->hasStalePlanGeneration());
    }

    // ── Die Seite räumt auf ──────────────────────────────────────────────

    public function test_opening_the_page_clears_a_stuck_flag(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user, ['plan_generating' => true]);
        TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []])
            ->forceFill(['is_active' => true])->save();

        $this->actingAs($user)
            ->get(route('events.plan.show', $event))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('event.plan_generating', false)
                ->where('event.plan_error', null));

        $this->assertFalse((bool) $event->refresh()->plan_generating);
    }

    /** Ohne Plan bekommt der Athlet gesagt, warum nichts da ist. */
    public function test_without_a_plan_the_interruption_is_named(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user, ['plan_generating' => true]);

        $this->actingAs($user)
            ->get(route('events.plan.show', $event))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('event.plan_generating', false)
                ->where('event.plan_error', 'Die letzte Plan-Erstellung wurde unterbrochen. Bitte starte sie erneut.'));
    }

    /** Ein wirklich laufender Job wird nicht weggeräumt. */
    public function test_a_running_generation_is_left_alone(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user, [
            'plan_generating'    => true,
            'plan_generating_at' => now()->subMinutes(2),
        ]);

        $this->actingAs($user)
            ->get(route('events.plan.show', $event))
            ->assertInertia(fn ($page) => $page->where('event.plan_generating', true));

        $this->assertTrue((bool) $event->refresh()->plan_generating);
    }

    // ── Die Schaltfläche wirkt wieder ────────────────────────────────────

    public function test_a_stuck_flag_no_longer_blocks_a_new_run(): void
    {
        Queue::fake();

        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user, ['plan_generating' => true]);

        $this->actingAs($user)
            ->postJson(route('events.plan.generate', $event))
            ->assertOk();

        Queue::assertPushed(GenerateEventTrainingPlanJob::class);
        $this->assertNotNull($event->refresh()->plan_generating_at, 'Der neue Lauf wird mit Zeitstempel vermerkt');
    }

    /** Ein laufender Job wird nicht verdoppelt — sonst löschen sich zwei Läufe gegenseitig die Pläne. */
    public function test_a_running_generation_is_not_started_twice(): void
    {
        Queue::fake();

        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user, [
            'plan_generating'    => true,
            'plan_generating_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)->postJson(route('events.plan.generate', $event))->assertOk();

        Queue::assertNotPushed(GenerateEventTrainingPlanJob::class);
    }

    // ── Die Statusabfrage hängt nicht mehr ───────────────────────────────

    public function test_the_status_endpoint_reports_a_stuck_run_as_failed(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user, ['plan_generating' => true]);

        $this->actingAs($user)
            ->getJson(route('events.plan.generate-status', $event))
            ->assertOk()
            ->assertJson(['status' => 'failed']);

        $this->assertFalse((bool) $event->refresh()->plan_generating);
    }

    public function test_the_status_endpoint_still_reports_a_running_job(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user, [
            'plan_generating'    => true,
            'plan_generating_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->getJson(route('events.plan.generate-status', $event))
            ->assertJson(['status' => 'generating']);
    }
}
