<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use App\Services\TelegramBriefing;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Coach-Chat und das Telegram-Briefing an einem Tag mit Doppel-Schwelle.
 *
 * Das Gerüst legt im norwegischen Modell zwei Läufe auf einen Tag, der
 * Coach-Chat kannte dagegen nur „ein Lauf pro Tag": `create_training_session`
 * ersetzte die Morgeneinheit, `move_training_session` nahm am Zieltag nur den
 * ersten Lauf weg, und beide Hälften tragen denselben Typ — `modify` traf
 * immer den Morgen.
 */
class CoachDoubleDayTest extends TestCase
{
    use RefreshDatabase;

    private const TUESDAY = '2026-10-27';
    private const WEDNESDAY = '2026-10-28';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 09:00:00'); // Montag
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: TrainingPlan} */
    private function athlete(string $model = 'norwegian', int $eveningMin = 45): array
    {
        $user = User::factory()->create(['name' => 'Jan Tester']);
        $user->runnerProfile()->create([
            'threshold_speed'     => 4.25,
            'training_model'      => $model,
            'weekly_availability' => collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
                ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => 60, 'second_min' => $eveningMin]])
                ->all(),
        ]);

        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Halbmarathon', 'event_date' => '2027-02-28',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        return [$user->refresh(), $plan];
    }

    private function plannedRun(User $user, TrainingPlan $plan, string $date, string $title, int $sort, string $type = 'tempo_run'): TrainingSession
    {
        return TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $plan->event_id,
            'planned_date' => $date, 'type' => $type, 'title' => $title, 'description' => '',
            'duration_min' => 50, 'distance_km' => 10, 'intensity' => 'high', 'status' => 'planned', 'sort_order' => $sort,
        ]);
    }

    /** @return array{0: TrainingSession, 1: TrainingSession} */
    private function doubleDay(User $user, TrainingPlan $plan, string $date = self::TUESDAY): array
    {
        return [
            $this->plannedRun($user, $plan, $date, 'Morgens: 5×6 min', 3),
            $this->plannedRun($user, $plan, $date, 'Abends: 10×1000 m', 4),
        ];
    }

    private function tool(User $user, string $tool, array $args): array
    {
        $method = new \ReflectionMethod(CoachChatService::class, 'executeCoachTool');
        $method->setAccessible(true);

        return $method->invoke(app(CoachChatService::class), $user->fresh(), $tool, $args);
    }

    private function runsOn(User $user, string $date)
    {
        return TrainingSession::where('user_id', $user->id)->whereDate('planned_date', $date)
            ->where('type', '!=', 'rest')->orderBy('sort_order')->get();
    }

    public function test_modify_with_part_changes_the_evening_half(): void
    {
        [$user, $plan] = $this->athlete();
        [$am, $pm] = $this->doubleDay($user, $plan);

        $this->tool($user, 'modify_training_session', ['date' => self::TUESDAY, 'part' => 'pm', 'title' => 'Abends: 8×1000 m']);

        $this->assertSame('Morgens: 5×6 min', $am->fresh()->title);
        $this->assertSame('Abends: 8×1000 m', $pm->fresh()->title);
    }

    public function test_create_without_part_on_a_double_day_changes_nothing(): void
    {
        [$user, $plan] = $this->athlete();
        $this->doubleDay($user, $plan);

        $result = $this->tool($user, 'create_training_session', ['date' => self::TUESDAY, 'type' => 'easy_run']);

        $this->assertNull($result['action']);
        $this->assertStringContainsString('part', $result['message']);
        $this->assertSame(['Morgens: 5×6 min', 'Abends: 10×1000 m'], $this->runsOn($user, self::TUESDAY)->pluck('title')->all());
    }

    public function test_create_with_part_replaces_only_that_half(): void
    {
        [$user, $plan] = $this->athlete();
        [$am, $pm] = $this->doubleDay($user, $plan);

        $this->tool($user, 'create_training_session', ['date' => self::TUESDAY, 'type' => 'easy_run', 'part' => 'pm', 'title' => 'Locker 30 min', 'duration_min' => 30]);

        $runs = $this->runsOn($user, self::TUESDAY);
        $this->assertCount(2, $runs);
        $this->assertSame('Morgens: 5×6 min', $am->fresh()->title);
        $this->assertSame('easy_run', $pm->fresh()->type);
    }

    public function test_a_single_threshold_becomes_a_double_where_the_skeleton_would_allow_it(): void
    {
        [$user, $plan] = $this->athlete();
        $this->plannedRun($user, $plan, self::TUESDAY, 'Schwelle 5×6 min', 3);

        $this->tool($user, 'create_training_session', ['date' => self::TUESDAY, 'type' => 'tempo_run', 'part' => 'pm', 'title' => 'Abends: 10×1000 m', 'duration_min' => 50]);

        $this->assertSame(['Schwelle 5×6 min', 'Abends: 10×1000 m'], $this->runsOn($user, self::TUESDAY)->pluck('title')->all());
    }

    public function test_a_morning_half_goes_in_front(): void
    {
        [$user, $plan] = $this->athlete();
        $this->plannedRun($user, $plan, self::TUESDAY, 'Schwelle 10×1000 m', 3);

        $this->tool($user, 'create_training_session', ['date' => self::TUESDAY, 'type' => 'tempo_run', 'part' => 'am', 'title' => 'Morgens: 5×6 min', 'duration_min' => 50]);

        $this->assertSame(['Morgens: 5×6 min', 'Schwelle 10×1000 m'], $this->runsOn($user, self::TUESDAY)->pluck('title')->all());
    }

    /** Ohne Abendfenster bleibt es bei „ein Lauf pro Tag". */
    public function test_no_double_without_an_evening_window(): void
    {
        [$user, $plan] = $this->athlete(eveningMin: 0);
        $this->plannedRun($user, $plan, self::TUESDAY, 'Schwelle 5×6 min', 3);

        $this->tool($user, 'create_training_session', ['date' => self::TUESDAY, 'type' => 'tempo_run', 'part' => 'pm', 'title' => 'Abends: 10×1000 m']);

        $this->assertSame(['Abends: 10×1000 m'], $this->runsOn($user, self::TUESDAY)->pluck('title')->all());
    }

    public function test_no_double_in_the_polarized_model(): void
    {
        [$user, $plan] = $this->athlete(model: 'polarized');
        $this->plannedRun($user, $plan, self::TUESDAY, 'Schwelle 5×6 min', 3);

        $this->tool($user, 'create_training_session', ['date' => self::TUESDAY, 'type' => 'tempo_run', 'part' => 'pm', 'title' => 'Abends: 10×1000 m']);

        $this->assertCount(1, $this->runsOn($user, self::TUESDAY));
    }

    public function test_moving_a_double_day_without_part_moves_both_halves(): void
    {
        [$user, $plan] = $this->athlete();
        $this->doubleDay($user, $plan);
        $this->plannedRun($user, $plan, self::WEDNESDAY, 'Locker 40 min', 5, 'easy_run');

        $this->tool($user, 'move_training_session', ['from_date' => self::TUESDAY, 'to_date' => self::WEDNESDAY]);

        $this->assertSame(['Morgens: 5×6 min', 'Abends: 10×1000 m'], $this->runsOn($user, self::WEDNESDAY)->pluck('title')->all());
        // Der leere Dienstag bekommt einen Ruhetag statt eines Lochs.
        $this->assertSame(['rest'], TrainingSession::where('user_id', $user->id)->whereDate('planned_date', self::TUESDAY)->pluck('type')->all());
    }

    public function test_moving_one_half_leaves_the_other(): void
    {
        [$user, $plan] = $this->athlete();
        [$am, $pm] = $this->doubleDay($user, $plan);

        $this->tool($user, 'move_training_session', ['from_date' => self::TUESDAY, 'to_date' => self::WEDNESDAY, 'part' => 'pm']);

        $this->assertSame(self::TUESDAY, $am->fresh()->planned_date->toDateString());
        $this->assertSame(self::WEDNESDAY, $pm->fresh()->planned_date->toDateString());
    }

    /** Ein Lauf, der auf einen Doppel-Tag geschoben wird, ersetzt beide Hälften — nicht nur die erste. */
    public function test_moving_onto_a_double_day_clears_both_halves(): void
    {
        [$user, $plan] = $this->athlete();
        $this->doubleDay($user, $plan, self::WEDNESDAY);
        $this->plannedRun($user, $plan, self::TUESDAY, 'Langer Lauf', 2, 'long_run');

        $this->tool($user, 'move_training_session', ['from_date' => self::TUESDAY, 'to_date' => self::WEDNESDAY]);

        $this->assertSame(['Langer Lauf'], $this->runsOn($user, self::WEDNESDAY)->pluck('title')->all());
    }

    public function test_deleting_one_half_keeps_the_other(): void
    {
        [$user, $plan] = $this->athlete();
        [$am, $pm] = $this->doubleDay($user, $plan);

        $this->tool($user, 'delete_training_session', ['date' => self::TUESDAY, 'part' => 'am']);

        $this->assertNull($am->fresh());
        $this->assertNotNull($pm->fresh());
    }

    /** Ohne Doppel-Tag bleibt alles beim Alten: ein zweiter Lauf ersetzt den ersten. */
    public function test_a_second_run_still_replaces_on_a_normal_day(): void
    {
        [$user, $plan] = $this->athlete();
        $this->plannedRun($user, $plan, self::TUESDAY, 'Locker 40 min', 3, 'easy_run');

        $this->tool($user, 'create_training_session', ['date' => self::TUESDAY, 'type' => 'interval', 'part' => 'pm', 'title' => '6×800 m']);

        $this->assertSame(['6×800 m'], $this->runsOn($user, self::TUESDAY)->pluck('title')->all());
    }

    public function test_the_briefing_names_morning_and_evening(): void
    {
        Carbon::setTestNow(self::TUESDAY . ' 07:00:00');
        [$user, $plan] = $this->athlete();
        $this->doubleDay($user, $plan);

        $text = app(TelegramBriefing::class)->today($user->fresh());

        $this->assertStringContainsString('**Morgens: Morgens: 5×6 min', $text);
        $this->assertStringContainsString('**Abends: Abends: 10×1000 m', $text);
        $this->assertStringNotContainsString('Heute:', $text);
    }
}
