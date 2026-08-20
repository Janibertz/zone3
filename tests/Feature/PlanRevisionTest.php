<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\PlanRevision;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\PlanRevisionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Der Änderungsverlauf.
 *
 * Jede Neuberechnung löscht den Plan und legt einen neuen an. Was vorher
 * dort stand, war danach nicht mehr nachzulesen — und der Bericht des
 * Validators lag zwar in training_plans.context, ging aber mit dem Plan
 * unter und wurde nie angezeigt.
 */
class PlanRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function event(User $user): Event
    {
        return Event::create([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(40),
            'race_distance'       => '10km',
            'priority'            => 'A',
            'target_time_hours'   => 0,
            'target_time_minutes' => 45,
        ]);
    }

    private function oldSession(User $user, TrainingPlan $plan, string $date, array $attrs): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'user_id'          => $user->id,
            'training_plan_id' => $plan->id,
            'event_id'         => $plan->event_id,
            'planned_date'     => $date,
            'title'            => 'Einheit',
            'description'      => '',
            'intensity'        => 'low',
            'status'           => 'planned',
        ], $attrs));
    }

    // ── Der Vergleich ────────────────────────────────────────────────────

    public function test_a_replaced_session_shows_both_sides(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $date  = now()->addDay()->toDateString();

        $old = new Collection([
            $this->oldSession($user, $plan, $date, ['type' => 'interval', 'distance_km' => 10, 'duration_min' => 55]),
        ]);
        $new = [['date' => $date, 'type' => 'easy_run', 'distance_km' => 8, 'duration_min' => 45]];

        $changes = app(PlanRevisionRecorder::class)->diff($old, $new);

        $this->assertCount(1, $changes);
        $this->assertSame('changed', $changes[0]['kind']);
        $this->assertStringContainsString('Intervalltraining', $changes[0]['from']);
        $this->assertStringContainsString('Lockerer Lauf', $changes[0]['to']);
        $this->assertStringContainsString('8 km', $changes[0]['to']);
    }

    public function test_a_new_day_is_marked_as_added(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $date  = now()->addDays(2)->toDateString();

        $changes = app(PlanRevisionRecorder::class)->diff(
            new Collection(),
            [['date' => $date, 'type' => 'tempo_run', 'distance_km' => 12]],
        );

        $this->assertSame('added', $changes[0]['kind']);
        $this->assertNull($changes[0]['from']);
    }

    public function test_a_dropped_day_is_marked_as_removed(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $date  = now()->addDays(3)->toDateString();

        $old = new Collection([$this->oldSession($user, $plan, $date, ['type' => 'long_run', 'distance_km' => 22])]);

        $changes = app(PlanRevisionRecorder::class)->diff($old, []);

        $this->assertSame('removed', $changes[0]['kind']);
        $this->assertStringContainsString('Langer Lauf', $changes[0]['from']);
        $this->assertNull($changes[0]['to']);
    }

    /** Ein unveränderter Tag taucht nicht auf — sonst wäre der Verlauf Rauschen. */
    public function test_an_unchanged_day_produces_no_entry(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $date  = now()->addDay()->toDateString();

        $old = new Collection([
            $this->oldSession($user, $plan, $date, ['type' => 'easy_run', 'distance_km' => 8, 'duration_min' => 45]),
        ]);
        $new = [['date' => $date, 'type' => 'easy_run', 'title' => 'Ganz anderer Titel', 'distance_km' => 8, 'duration_min' => 45]];

        $this->assertSame([], app(PlanRevisionRecorder::class)->diff($old, $new));
    }

    /** Vergangene Tage werden nicht neu berechnet — sie gehören nicht in den Verlauf. */
    public function test_the_past_is_left_out(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        $old = new Collection([
            $this->oldSession($user, $plan, now()->subDays(3)->toDateString(), ['type' => 'interval', 'distance_km' => 10]),
        ]);

        $this->assertSame([], app(PlanRevisionRecorder::class)->diff($old, []));
    }

    /** Zwei Einheiten an einem Tag werden gemeinsam verglichen. */
    public function test_two_sessions_on_one_day_are_compared_together(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $date  = now()->addDay()->toDateString();

        $old = new Collection([
            $this->oldSession($user, $plan, $date, ['type' => 'easy_run', 'distance_km' => 8]),
            $this->oldSession($user, $plan, $date, ['type' => 'strength', 'duration_min' => 30]),
        ]);
        $new = [['date' => $date, 'type' => 'easy_run', 'distance_km' => 8]];

        $changes = app(PlanRevisionRecorder::class)->diff($old, $new);

        $this->assertCount(1, $changes);
        $this->assertSame('changed', $changes[0]['kind']);
        $this->assertStringContainsString('Krafttraining', $changes[0]['from']);
        $this->assertStringNotContainsString('Krafttraining', $changes[0]['to']);
    }

    // ── Der Eintrag ──────────────────────────────────────────────────────

    public function test_a_revision_is_written_and_survives_the_plan(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $date  = now()->addDay()->toDateString();

        app(PlanRevisionRecorder::class)->record(
            user:        $user,
            event:       $event,
            newPlan:     $plan,
            oldSessions: new Collection(),
            newSessions: [['date' => $date, 'type' => 'tempo_run', 'distance_km' => 12]],
            corrections: ['Dienstag war belegt — Einheit auf Mittwoch verschoben.'],
            triggeredBy: 'auto',
        );

        $revision = PlanRevision::where('event_id', $event->id)->firstOrFail();
        $this->assertSame($plan->id, $revision->training_plan_id);
        $this->assertSame('Dienstag war belegt — Einheit auf Mittwoch verschoben.', $revision->corrections[0]);

        // Die naechste Neuberechnung loescht den Plan. Der Eintrag bleibt.
        $plan->delete();

        $revision->refresh();
        $this->assertNull($revision->training_plan_id);
        $this->assertNotEmpty($revision->changes);
    }

    /** Ohne Änderung und ohne Korrektur entsteht kein Eintrag. */
    public function test_a_regeneration_that_changed_nothing_is_not_logged(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        $result = app(PlanRevisionRecorder::class)->record(
            user: $user, event: $event, newPlan: $plan,
            oldSessions: new Collection(), newSessions: [], triggeredBy: 'auto',
        );

        $this->assertNull($result);
        $this->assertSame(0, PlanRevision::count());
    }

    /** Der erste Plan bekommt immer einen Eintrag — der Verlauf braucht einen Anfang. */
    public function test_the_first_plan_is_always_logged(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        app(PlanRevisionRecorder::class)->record(
            user: $user, event: $event, newPlan: $plan,
            oldSessions: new Collection(), newSessions: [], triggeredBy: 'initial',
        );

        $this->assertSame(1, PlanRevision::count());
        $this->assertSame('Erster Plan', PlanRevision::first()->triggerLabel());
    }

    // ── Die Anzeige ──────────────────────────────────────────────────────

    public function test_the_plan_page_carries_the_revisions(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        app(PlanRevisionRecorder::class)->record(
            user: $user, event: $event, newPlan: $plan,
            oldSessions: new Collection(),
            newSessions: [['date' => now()->addDay()->toDateString(), 'type' => 'interval', 'distance_km' => 10]],
            triggeredBy: 'user',
        );

        $this->actingAs($user)
            ->get(route('events.plan.show', $event))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('revisions', 1)
                ->where('revisions.0.label', 'Nach deiner Rückmeldung')
                ->where('revisions.0.changes.0.kind', 'added'));
    }

    /** Der Verlauf eines anderen Athleten taucht nirgends auf. */
    public function test_revisions_are_scoped_to_the_athlete(): void
    {
        $user  = User::factory()->onboarded()->create();
        $other = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        PlanRevision::create([
            'user_id'  => $other->id,
            'event_id' => $event->id,
            'triggered_by' => 'auto',
            'changes'  => [['date' => '2026-09-01', 'label' => 'Di, 1. Sep', 'kind' => 'added', 'from' => null, 'to' => 'X']],
        ]);

        $this->actingAs($user)
            ->get(route('events.plan.show', $event))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('revisions', 0));
    }
}
