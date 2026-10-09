<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Einheiten ohne Plan: anhaengen, was dazugehoert — loeschen, was leer ist.
 *
 * Die Anweisung war „hau die verwaisten Einheiten weg". Beim Nachsehen trug
 * die Menge aber 45 eigene Bewertungen des Athleten von 128 insgesamt, und
 * die fuettern den Prompt. Diese Tests halten die Grenze fest, an der das
 * Aufraeumen aufhoert: **was Inhalt hat, wird nicht geloescht.**
 *
 * Geprueft wird die Logik der Migration
 * `2026_10_09_140000_adopt_or_remove_orphaned_sessions`, hier nachgebaut als
 * derselbe Satz Regeln — eine Migration laeuft unter `RefreshDatabase` schon
 * beim Aufbau der Datenbank und ist danach nicht wiederholbar.
 */
class OrphanedSessionCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** Dieselben Regeln wie in der Migration. */
    private function cleanUp(): array
    {
        $orphans = fn () => DB::table('training_sessions')
            ->whereNull('training_plan_id')
            ->where('was_unplanned', false);

        $adopted = 0;

        foreach ((clone $orphans())->whereNotNull('event_id')->distinct()->pluck('event_id') as $eventId) {
            $planId = TrainingPlan::where('event_id', $eventId)->orderByDesc('id')->value('id');

            if (! $planId) {
                continue;
            }

            $adopted += (clone $orphans())->where('event_id', $eventId)
                ->update(['training_plan_id' => $planId, 'updated_at' => now()]);
        }

        $removed = (clone $orphans())
            ->whereNull('activity_id')
            ->whereNull('rating')
            ->whereNull('feeling_notes')
            ->whereNull('coach_review')
            ->where('status', '<>', 'completed')
            ->whereDate('planned_date', '<', now()->toDateString())
            ->delete();

        return ['adopted' => $adopted, 'removed' => $removed, 'left' => (clone $orphans())->count()];
    }

    private function event(): Event
    {
        return Event::create([
            'user_id'       => $this->user->id,
            'name'          => 'Hannover Halbmarathon',
            'event_date'    => now()->addMonths(2)->toDateString(),
            'race_distance' => 'half_marathon',
            'priority'      => 'A',
        ]);
    }

    private function orphan(array $attrs = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'user_id'      => $this->user->id,
            'planned_date' => now()->subMonth()->toDateString(),
            'type'         => 'easy_run',
            'title'        => 'Basislauf',
            'status'       => 'planned',
            'intensity'    => 'low',
        ], $attrs));
    }

    public function test_an_orphan_with_an_event_is_adopted_by_that_events_plan(): void
    {
        $event = $this->event();
        $plan  = TrainingPlan::create(['user_id' => $this->user->id, 'event_id' => $event->id, 'is_active' => false, 'sessions' => []]);

        $session = $this->orphan(['event_id' => $event->id, 'status' => 'completed', 'rating' => 4]);

        $result = $this->cleanUp();

        $this->assertSame(1, $result['adopted']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame($plan->id, $session->fresh()->training_plan_id);
    }

    /**
     * Der Kern dieser Datei. 45 von 128 bewerteten Einheiten waren verwaist;
     * die Bewertungen stehen im Plan-Prompt unter „Bisherige
     * Einheitsbewertungen". Ein Aufraeumen, das sie mitnimmt, kostet ein
     * Drittel des Feedbacks, aus dem der Plan lernt.
     */
    public function test_a_rated_orphan_is_never_deleted(): void
    {
        $session = $this->orphan(['status' => 'completed', 'rating' => 5]);

        $result = $this->cleanUp();

        $this->assertSame(0, $result['removed']);
        $this->assertSame(1, $result['left'], 'Bleibt stehen und sichtbar, statt still zu verschwinden');
        $this->assertNotNull($session->fresh());
    }

    public function test_an_orphan_linked_to_a_real_activity_is_never_deleted(): void
    {
        $activity = \App\Models\Activity::create([
            'user_id'       => $this->user->id,
            'strava_id'     => 987654,
            'name'          => 'Geiler Sonntagslauf',
            'type'          => 'Run',
            'start_date'    => now()->subMonth(),
            'distance'      => 24640,
            'moving_time'   => 7200,
            'elapsed_time'  => 7200,
            'average_speed' => 24640 / 7200,
        ]);

        $this->orphan(['activity_id' => $activity->id]);

        $this->assertSame(0, $this->cleanUp()['removed']);
    }

    public function test_an_orphan_with_a_feeling_note_is_never_deleted(): void
    {
        $this->orphan(['feeling_notes' => 'Beine schwer, Kopf gut']);

        $this->assertSame(0, $this->cleanUp()['removed']);
    }

    /** Leer, vorbei, ohne Zuordnung — das ist der Fall, der weg darf. */
    public function test_an_empty_past_orphan_is_removed(): void
    {
        $session = $this->orphan(['planned_date' => now()->subMonths(4)->toDateString()]);

        $this->assertSame(1, $this->cleanUp()['removed']);
        $this->assertNull($session->fresh());
    }

    /** Ein geplanter Tag in der Zukunft gehoert niemandem weggeraeumt. */
    public function test_a_future_orphan_stays(): void
    {
        $this->orphan(['planned_date' => now()->addWeek()->toDateString()]);

        $this->assertSame(0, $this->cleanUp()['removed']);
    }

    /** Was der Athlet selbst eingetragen hat, war nie verwaist. */
    public function test_an_unplanned_session_is_not_touched(): void
    {
        $session = $this->orphan(['was_unplanned' => true, 'planned_date' => now()->subMonths(4)->toDateString()]);

        $result = $this->cleanUp();

        $this->assertSame(0, $result['removed']);
        $this->assertSame(0, $result['adopted']);
        $this->assertNotNull($session->fresh());
    }

    /** Ohne Plan zum Event bleibt die Einheit, wenn sie Inhalt hat. */
    public function test_an_orphan_whose_event_has_no_plan_is_left_alone(): void
    {
        $event   = $this->event();
        $session = $this->orphan(['event_id' => $event->id, 'status' => 'completed', 'rating' => 3]);

        $result = $this->cleanUp();

        $this->assertSame(0, $result['adopted']);
        $this->assertSame(0, $result['removed']);
        $this->assertNull($session->fresh()->training_plan_id);
    }

    public function test_the_newest_plan_wins_when_an_event_has_several(): void
    {
        $event = $this->event();
        TrainingPlan::create(['user_id' => $this->user->id, 'event_id' => $event->id, 'is_active' => false, 'sessions' => []]);
        $newer = TrainingPlan::create(['user_id' => $this->user->id, 'event_id' => $event->id, 'is_active' => false, 'sessions' => []]);

        $session = $this->orphan(['event_id' => $event->id, 'status' => 'completed']);

        $this->cleanUp();

        $this->assertSame($newer->id, $session->fresh()->training_plan_id);
    }
}
