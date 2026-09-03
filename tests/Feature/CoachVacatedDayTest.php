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
 * Der Tag, von dem der Coach die Einheit wegnimmt.
 *
 * Gemeldet, und der Hergang ist genau nachvollziehbar: Jan hatte das
 * Mittwochstraining aus Zeitgründen abgesagt — richtig als ausgelassen
 * vermerkt. Die Neuberechnung legte die Einheit daraufhin auf Donnerstag.
 * Dann sagte er dem Coach, er schaffe sie doch noch am Mittwoch, und der
 * Coach schob sie zurück. Im Plan stand der Donnerstag danach **leer**.
 *
 * `move_training_session` setzt nur `planned_date` um und speichert. Was am
 * Herkunftstag zurückbleibt, sah niemand nach. In der App ist das ein Loch
 * mitten in der Woche — kein Training, kein Ruhetag, nichts.
 *
 * Die Kontrolle in `RegeneratePlanJob::sealGaps()` greift hier nicht: sie
 * läuft in der Neuberechnung, und ein Verschieben im Chat löst keine aus.
 * Ein Tag muss dort geschlossen werden, wo er geleert wird.
 */
class CoachVacatedDayTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: TrainingPlan} */
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

    /** Über den Query Builder, damit `planned_date` ohne Uhrzeit steht. */
    private function plannedSession(User $user, ?TrainingPlan $plan, string $date, string $type = 'easy_run'): int
    {
        return DB::table('training_sessions')->insertGetId([
            'user_id'          => $user->id,
            'training_plan_id' => $plan?->id,
            'planned_date'     => $date,
            'type'             => $type,
            'title'            => 'Lockerer Lauf',
            'description'      => 'Easy',
            'distance_km'      => 5.5,
            'duration_min'     => 30,
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

    /** @return \Illuminate\Database\Eloquent\Collection<int, TrainingSession> */
    private function sessionsOn(User $user, string $date)
    {
        return TrainingSession::where('user_id', $user->id)->whereDate('planned_date', $date)->get();
    }

    // ── Der gemeldete Fall ───────────────────────────────────────────────

    public function test_the_day_the_session_was_moved_away_from_is_not_left_empty(): void
    {
        [$user, $plan] = $this->athlete();

        $thursday  = now()->addDay()->toDateString();
        $wednesday = now()->toDateString();

        $this->plannedSession($user, $plan, $thursday);

        $this->runTool($user, 'move_training_session', [
            'from_date' => $thursday,
            'to_date'   => $wednesday,
        ]);

        $moved = $this->sessionsOn($user, $wednesday);
        $this->assertCount(1, $moved, 'Die Einheit muss am Zieltag stehen');

        $left = $this->sessionsOn($user, $thursday);
        $this->assertCount(1, $left, "Der {$thursday} darf nach dem Verschieben kein Loch sein");
        $this->assertSame('rest', $left->first()->type);
    }

    /**
     * Der Ruhetag hängt am aktiven Plan — sonst wäre er da und trotzdem
     * unsichtbar, weil die Planseite nur Einheiten des aktiven Plans lädt.
     */
    public function test_the_rest_day_belongs_to_the_active_plan(): void
    {
        [$user, $plan] = $this->athlete();

        $from = now()->addDay()->toDateString();
        $to   = now()->addDays(2)->toDateString();

        $this->plannedSession($user, $plan, $from);
        $this->runTool($user, 'move_training_session', ['from_date' => $from, 'to_date' => $to]);

        $this->assertSame($plan->id, $this->sessionsOn($user, $from)->first()->training_plan_id);
    }

    /**
     * Steht am Herkunftstag noch etwas anderes — eine Krafteinheit, ein
     * abgehaktes Alternativtraining —, ist der Tag kein Loch und bekommt
     * auch keinen Ruhetag danebengesetzt.
     */
    public function test_a_day_that_still_holds_something_gets_no_rest_day(): void
    {
        [$user, $plan] = $this->athlete();

        $from = now()->addDay()->toDateString();
        $to   = now()->addDays(2)->toDateString();

        $this->plannedSession($user, $plan, $from, 'easy_run');
        $this->plannedSession($user, $plan, $from, 'strength');

        $this->runTool($user, 'move_training_session', [
            'from_date' => $from,
            'to_date'   => $to,
            'type'      => 'easy_run',
        ]);

        $left = $this->sessionsOn($user, $from);
        $this->assertCount(1, $left);
        $this->assertSame('strength', $left->first()->type, 'Die Krafteinheit bleibt, ein Ruhetag kommt nicht dazu');
    }

    /**
     * Löschen hinterlässt dieselbe Lücke. „Nichts geplant" ist eine Aussage,
     * die der Plan treffen kann — ein Loch trifft gar keine.
     */
    public function test_deleting_the_last_session_leaves_a_rest_day(): void
    {
        [$user, $plan] = $this->athlete();

        $date = now()->addDays(2)->toDateString();
        $this->plannedSession($user, $plan, $date);

        $this->runTool($user, 'delete_training_session', ['date' => $date]);

        $left = $this->sessionsOn($user, $date);
        $this->assertCount(1, $left);
        $this->assertSame('rest', $left->first()->type);
    }

    /**
     * Und der Zieltag darf davon nichts abbekommen: dort steht die
     * verschobene Einheit, kein zusätzlicher Ruhetag.
     */
    public function test_the_target_day_keeps_only_the_moved_session(): void
    {
        [$user, $plan] = $this->athlete();

        $from = now()->addDay()->toDateString();
        $to   = now()->addDays(2)->toDateString();

        $this->plannedSession($user, $plan, $from);
        $this->runTool($user, 'move_training_session', ['from_date' => $from, 'to_date' => $to]);

        $target = $this->sessionsOn($user, $to);
        $this->assertCount(1, $target);
        $this->assertSame('easy_run', $target->first()->type);
    }
}
