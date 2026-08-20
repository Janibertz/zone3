<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gemeldet: „Ich möchte am Sonntag einen Longrun von ca. 25 km machen.
 * Plane das bitte in meinem aktuellen Plan ein." Der Coach antwortete
 * ausführlich und überzeugend — „den ersetzen wir", samt Tabelle und
 * Pace-Vorgabe. Im Plan stand danach unverändert der alte 7,2-km-Lauf.
 *
 * Der Grund: der Coach hatte genau ein Werkzeug für Einheiten, und das hieß
 * modify_today_session. Für den Sonntag in drei Tagen passte es nicht. Wenn
 * kein Werkzeug passt, ruft ein Sprachmodell keines auf — es beschreibt die
 * Änderung stattdessen. Der Athlet liest eine perfekte Zusage und findet
 * den alten Plan vor.
 */
class CoachPlanEditTest extends TestCase
{
    use RefreshDatabase;

    private function athlete(): array
    {
        $user = User::factory()->create();

        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Berlin Marathon',
            'event_date'          => now()->addDays(38),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);

        $plan = TrainingPlan::create([
            'user_id'   => $user->id,
            'event_id'  => $event->id,
            'sessions'  => [],
            'is_active' => true,
        ]);

        return [$user, $plan];
    }

    /**
     * Über den Query Builder, damit planned_date wie in der echten
     * MySQL-DATE-Spalte ohne Uhrzeit steht.
     */
    private function plannedSession(User $user, ?TrainingPlan $plan, string $date, string $type = 'easy_run', float $km = 7.2): int
    {
        return DB::table('training_sessions')->insertGetId([
            'user_id'          => $user->id,
            'training_plan_id' => $plan?->id,
            'planned_date'     => $date,
            'type'             => $type,
            'title'            => 'Locker zurückrollen',
            'description'      => 'Easy',
            'distance_km'      => $km,
            'duration_min'     => 40,
            'intensity'        => 'low',
            'status'           => 'planned',
            'sort_order'       => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function runTool(User $user, string $tool, array $args): array
    {
        $method = new \ReflectionMethod(CoachChatService::class, 'executeCoachTool');
        $method->setAccessible(true);

        return $method->invoke(app(CoachChatService::class), $user->fresh(), $tool, $args);
    }

    // ── Der gemeldete Fall ───────────────────────────────────────────────

    public function test_the_coach_can_change_a_session_on_a_future_day(): void
    {
        [$user, $plan] = $this->athlete();
        $sunday = now()->addDays(3)->toDateString();
        $id     = $this->plannedSession($user, $plan, $sunday);

        $result = $this->runTool($user, 'modify_training_session', [
            'date'         => $sunday,
            'type'         => 'long_run',
            'title'        => 'Longrun 25 km',
            'distance_km'  => 25,
            'duration_min' => 145,
            'pace_target'  => '5:35-6:05',
        ]);

        $session = TrainingSession::find($id);
        $this->assertSame('long_run', $session->type);
        $this->assertEquals(25, $session->distance_km);
        $this->assertNotNull($result['action'], 'Die Änderung muss im Chat sichtbar gemeldet werden');
        $this->assertTrue($result['action']['reload']);
    }

    /** Und sie landet im aktiven Plan — sonst zeigt die Planseite sie nicht. */
    public function test_a_created_session_lands_in_the_active_plan(): void
    {
        [$user, $plan] = $this->athlete();
        $date = now()->addDays(4)->toDateString();

        $this->runTool($user, 'create_training_session', [
            'date' => $date, 'type' => 'strength', 'title' => 'Kettlebell', 'duration_min' => 30,
        ]);

        $session = TrainingSession::where('user_id', $user->id)->whereDate('planned_date', $date)->first();

        $this->assertNotNull($session);
        $this->assertSame($plan->id, $session->training_plan_id);
        $this->assertSame($plan->event_id, $session->event_id);
    }

    /** Gibt es an dem Tag noch gar nichts, wird die Einheit angelegt. */
    public function test_modifying_an_empty_day_creates_the_session(): void
    {
        [$user, $plan] = $this->athlete();
        $date = now()->addDays(5)->toDateString();

        $this->runTool($user, 'modify_training_session', [
            'date' => $date, 'type' => 'long_run', 'distance_km' => 22,
        ]);

        $session = TrainingSession::where('user_id', $user->id)->whereDate('planned_date', $date)->first();

        $this->assertNotNull($session);
        $this->assertSame('long_run', $session->type);
        $this->assertSame($plan->id, $session->training_plan_id);
        $this->assertNotEmpty($session->title, 'Pflichtfelder müssen vorbelegt sein');
    }

    // ── Die Hausregel gilt auch im Chat ──────────────────────────────────

    /** Ein zweiter Lauf am selben Tag ersetzt den ersten, statt ihn zu ergänzen. */
    public function test_a_second_run_replaces_instead_of_doubling(): void
    {
        [$user, $plan] = $this->athlete();
        $date = now()->addDays(2)->toDateString();
        $this->plannedSession($user, $plan, $date, 'easy_run');

        $this->runTool($user, 'create_training_session', [
            'date' => $date, 'type' => 'long_run', 'title' => 'Longrun', 'distance_km' => 24,
        ]);

        $runs = TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $date)
            ->whereIn('type', \App\Services\TrainingPlanValidator::RUN_TYPES)
            ->get();

        $this->assertCount(1, $runs, 'Ein Lauftraining pro Tag — auch über den Chat');
        $this->assertSame('long_run', $runs->first()->type);
    }

    /** Kraft neben dem Lauf bleibt erlaubt. */
    public function test_strength_may_be_added_next_to_a_run(): void
    {
        [$user, $plan] = $this->athlete();
        $date = now()->addDays(2)->toDateString();
        $this->plannedSession($user, $plan, $date, 'easy_run');

        $this->runTool($user, 'create_training_session', [
            'date' => $date, 'type' => 'core', 'title' => 'Core', 'duration_min' => 20,
        ]);

        $this->assertCount(2, TrainingSession::where('user_id', $user->id)->whereDate('planned_date', $date)->get());
    }

    // ── Verschieben und Löschen ──────────────────────────────────────────

    public function test_a_session_can_be_moved_to_another_day(): void
    {
        [$user, $plan] = $this->athlete();
        $from = now()->addDays(3)->toDateString();
        $to   = now()->addDays(2)->toDateString();
        $id   = $this->plannedSession($user, $plan, $from, 'long_run', 24);

        $this->runTool($user, 'move_training_session', ['from_date' => $from, 'to_date' => $to]);

        $this->assertSame($to, TrainingSession::find($id)->planned_date->toDateString());
    }

    /** Am Zieltag räumt der Umzug einen kollidierenden Lauf weg. */
    public function test_moving_onto_an_occupied_day_clears_the_other_run(): void
    {
        [$user, $plan] = $this->athlete();
        $from = now()->addDays(3)->toDateString();
        $to   = now()->addDays(2)->toDateString();
        $id   = $this->plannedSession($user, $plan, $from, 'long_run', 24);
        $this->plannedSession($user, $plan, $to, 'easy_run');

        $this->runTool($user, 'move_training_session', ['from_date' => $from, 'to_date' => $to]);

        $onTarget = TrainingSession::where('user_id', $user->id)->whereDate('planned_date', $to)->get();
        $this->assertCount(1, $onTarget);
        $this->assertSame($id, $onTarget->first()->id);
    }

    public function test_a_session_can_be_deleted(): void
    {
        [$user, $plan] = $this->athlete();
        $date = now()->addDays(3)->toDateString();
        $id   = $this->plannedSession($user, $plan, $date);

        $result = $this->runTool($user, 'delete_training_session', ['date' => $date]);

        $this->assertNull(TrainingSession::find($id));
        $this->assertNotNull($result['action']);
    }

    // ── Was der Coach nicht darf ─────────────────────────────────────────

    /** Vergangene Tage bleiben, wie sie sind — sonst ist jede Auswertung wertlos. */
    public function test_past_days_are_not_rewritten(): void
    {
        [$user, $plan] = $this->athlete();
        $yesterday = now()->subDay()->toDateString();
        $id        = $this->plannedSession($user, $plan, $yesterday);

        $result = $this->runTool($user, 'modify_training_session', [
            'date' => $yesterday, 'type' => 'interval', 'distance_km' => 15,
        ]);

        $this->assertNull($result['action']);
        $this->assertSame('easy_run', TrainingSession::find($id)->type);
    }

    /** Eine absolvierte Einheit wird nicht nachträglich umgeschrieben. */
    public function test_a_completed_session_is_left_alone(): void
    {
        [$user, $plan] = $this->athlete();
        $today = now()->toDateString();
        $id    = $this->plannedSession($user, $plan, $today);
        TrainingSession::where('id', $id)->update(['status' => 'completed']);

        $result = $this->runTool($user, 'modify_training_session', [
            'date' => $today, 'type' => 'interval',
        ]);

        $this->assertNull($result['action']);
        $this->assertSame('easy_run', TrainingSession::find($id)->type);
    }

    /** Der alte Werkzeugname bleibt gültig, damit nichts still ausfällt. */
    public function test_the_old_tool_name_still_works(): void
    {
        [$user, $plan] = $this->athlete();
        $id = $this->plannedSession($user, $plan, now()->toDateString());

        $this->runTool($user, 'modify_today_session', ['type' => 'tempo_run', 'title' => 'Tempolauf']);

        $this->assertSame('tempo_run', TrainingSession::find($id)->type);
    }
}
