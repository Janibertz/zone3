<?php

namespace Tests\Feature;

use App\Http\Controllers\GoalCheckController;
use App\Models\Activity;
use App\Models\Event;
use App\Models\User;
use App\Services\GoalCheckService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ein Trainingsplan steht auf der Annahme, dass das Ziel erreichbar ist.
 * Nachgerechnet hat das bisher niemand — die Zahl wurde beim Anlegen des
 * Events eingetragen und dann monatelang durchgezogen.
 *
 * Der Kern der Prüfung: sie steht auf zwei Beinen. Die Prognose aus der
 * Schwellenpace beschreibt das Tempo, nicht die Fähigkeit, es über die
 * Distanz zu halten. Ein Läufer mit 4:22 Schwellenpace und 25
 * Wochenkilometern bekommt 3:26 als Marathonprognose — als Tempoaussage
 * richtig, als Rennaussage falsch.
 */
class GoalCheckTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 910000;

    private function athlete(float $thresholdPace = 4.3667): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $user->runnerProfile()->create(['threshold_speed' => $thresholdPace]);

        return $user->refresh();
    }

    /** Volle Wochen mit gleichmäßigem Umfang, damit der Schnitt eindeutig ist. */
    private function weeklyVolume(User $user, float $kmPerWeek, float $longest): void
    {
        $monday = CarbonImmutable::today()->startOfWeek();

        for ($w = 1; $w <= 4; $w++) {
            $start = $monday->subWeeks($w);
            Activity::create([
                'user_id' => $user->id, 'strava_id' => $this->seq++, 'name' => 'Lang', 'type' => 'Run',
                'distance' => $longest * 1000, 'moving_time' => 3600, 'elapsed_time' => 3600,
                'start_date' => $start,
            ]);
            $rest = max(0, $kmPerWeek - $longest);
            if ($rest > 0) {
                Activity::create([
                    'user_id' => $user->id, 'strava_id' => $this->seq++, 'name' => 'Rest', 'type' => 'Run',
                    'distance' => $rest * 1000, 'moving_time' => 3600, 'elapsed_time' => 3600,
                    'start_date' => $start->addDay(),
                ]);
            }
        }
    }

    private function marathon(User $user, int $h, int $m, int $daysUntil = 60): Event
    {
        return Event::create([
            'user_id' => $user->id, 'name' => 'Berlin Marathon',
            'event_date' => now()->addDays($daysUntil),
            'race_distance' => 'marathon', 'priority' => 'A',
            'target_time_hours' => $h, 'target_time_minutes' => $m,
        ]);
    }

    private function check(User $user, Event $event): ?array
    {
        return app(GoalCheckService::class)->forEvent($user->fresh(), $event->fresh());
    }

    // ── Der Fall, für den es die Prüfung gibt ────────────────────────────

    /**
     * Tempo trägt, Unterbau nicht. Eine Prüfung, die nur die Prognose
     * hochrechnet, würde hier „passt schon" sagen — mit einer Zahl, die
     * Sicherheit vortäuscht.
     */
    public function test_pace_carries_the_goal_but_the_base_does_not(): void
    {
        $user = $this->athlete();            // Schwelle 4:22 → Prognose 3:26
        $this->weeklyVolume($user, 25, 16);  // 25 km/Woche, längster 16 km
        $event = $this->marathon($user, 3, 30);

        $check = $this->check($user, $event);

        $this->assertNotNull($check);
        $this->assertSame('pace_ok_base_thin', $check['kind']);
        $this->assertStringContainsString('Unterbau', $check['detail']);
        $this->assertStringContainsString('25', $check['detail'], 'Die Zahl gehört in die Begründung');
        $this->assertNotNull($check['suggested'], 'Es braucht einen konkreten Vorschlag, nicht nur „langsamer"');
    }

    /**
     * Der Grund, warum der Median zaehlt und nicht der Mittelwert: drei ruhige
     * Wochen plus ein Backyard ergeben einen Schnitt, der einen Unterbau
     * vortaeuscht, den es nicht gibt.
     */
    public function test_one_huge_week_does_not_fake_a_base(): void
    {
        $user   = $this->athlete();
        $monday = CarbonImmutable::today()->startOfWeek();

        // Drei Alltagswochen um 25 km …
        foreach ([1, 2, 3] as $w) {
            Activity::create([
                'user_id' => $user->id, 'strava_id' => $this->seq++, 'name' => 'Woche', 'type' => 'Run',
                'distance' => 25000, 'moving_time' => 9000, 'elapsed_time' => 9000,
                'start_date' => $monday->subWeeks($w),
            ]);
        }
        // … und ein einzelner Backyard über 69 km.
        Activity::create([
            'user_id' => $user->id, 'strava_id' => $this->seq++, 'name' => 'Backyard', 'type' => 'Run',
            'distance' => 69000, 'moving_time' => 36000, 'elapsed_time' => 36000,
            'start_date' => $monday->subWeeks(4),
        ]);

        $event = $this->marathon($user, 3, 30);
        $check = $this->check($user, $event);

        // Mittelwert wäre 36 km (60 %) und hätte geschwiegen; der Median ist
        // 25 km (42 %) und stellt die Frage.
        $this->assertNotNull($check, 'Ein einzelner grosser Tag ersetzt keine Wiederholung');
        $this->assertSame('pace_ok_base_thin', $check['kind']);
    }

    /**
     * Der Fall, an dem die Schwelle von 60 auf 70 Prozent gehoben wurde:
     * zwei ruhige Wochen, zwei Ausreisser. Der Median liegt bei 36,6 km und
     * damit bei 61 % der noetigen 60 — mit der alten Schwelle ein
     * Prozentpunkt zu viel, um zu fragen.
     */
    public function test_a_base_at_two_thirds_is_not_enough(): void
    {
        $user   = $this->athlete();
        $monday = CarbonImmutable::today()->startOfWeek();

        foreach ([[1, 22.9], [2, 24.1], [3, 49.0], [4, 81.0]] as [$w, $km]) {
            Activity::create([
                'user_id' => $user->id, 'strava_id' => $this->seq++, 'name' => 'Woche', 'type' => 'Run',
                'distance' => $km * 1000, 'moving_time' => 9000, 'elapsed_time' => 9000,
                'start_date' => $monday->subWeeks($w),
            ]);
        }

        $check = $this->check($user, $this->marathon($user, 3, 30));

        $this->assertNotNull($check, 'Median 36,6 km sind 61 % der noetigen 60 — das traegt keinen Marathon');
        $this->assertSame('pace_ok_base_thin', $check['kind']);
    }

    /**
     * Ein Vorschlag, der dem bestehenden Ziel entspricht, ist keiner.
     *
     * Der Aufschlag mass zuerst gegen 80 % Unterbau statt gegen 100, und
     * gerundet wurde nach dem Vergleich statt davor. Bei 61 % Unterbau kamen
     * so 2,9 % Aufschlag heraus — 3:32, gerundet 3:30, also exakt die
     * Zielzeit, die zur Debatte stand. Der Knopf haette "Auf 3:30 aendern"
     * geheissen.
     */
    public function test_the_suggestion_is_meaningfully_slower_than_the_goal(): void
    {
        $user   = $this->athlete();
        $monday = CarbonImmutable::today()->startOfWeek();

        foreach ([[1, 22.9], [2, 24.1], [3, 49.0], [4, 81.0]] as [$w, $km]) {
            Activity::create([
                'user_id' => $user->id, 'strava_id' => $this->seq++, 'name' => 'Woche', 'type' => 'Run',
                'distance' => $km * 1000, 'moving_time' => 9000, 'elapsed_time' => 9000,
                'start_date' => $monday->subWeeks($w),
            ]);
        }

        $check = $this->check($user, $this->marathon($user, 3, 30));

        $this->assertNotSame($check['target'], $check['suggested']);
        $this->assertSame('3:40', $check['suggested'], 'Prognose 3:26 plus Aufschlag fuer 39 % fehlenden Unterbau');
    }

    /** Wer den Unterbau hat, wird nicht gefragt. */
    public function test_a_solid_base_with_matching_pace_asks_nothing(): void
    {
        $user = $this->athlete();
        $this->weeklyVolume($user, 65, 32);
        $event = $this->marathon($user, 3, 30);

        $this->assertNull($this->check($user, $event));
    }

    // ── Beide Richtungen ─────────────────────────────────────────────────

    public function test_an_unreachable_goal_is_flagged(): void
    {
        $user = $this->athlete();
        $this->weeklyVolume($user, 65, 32);
        $event = $this->marathon($user, 2, 50);   // deutlich schneller als 3:26

        $check = $this->check($user, $event);

        $this->assertSame('too_ambitious', $check['kind']);
        $this->assertNotNull($check['suggested']);
    }

    /** Das motivierendere Ende: die Daten tragen mehr, als der Athlet sich zutraut. */
    public function test_a_goal_that_sells_the_athlete_short_is_flagged(): void
    {
        $user = $this->athlete();
        $this->weeklyVolume($user, 65, 32);
        $event = $this->marathon($user, 5, 0);

        $check = $this->check($user, $event);

        $this->assertSame('too_conservative', $check['kind']);
        $this->assertSame('3:25', $check['suggested'], 'Vorgeschlagen wird die Prognose, auf fünf Minuten gerundet');
    }

    // ── Welches Rennen gemeint ist ───────────────────────────────────────

    /**
     * Ein kleineres Rennen zwischendurch darf die Frage nicht kapern.
     *
     * Zuerst nahm die Pruefung schlicht das naechste A- oder B-Event nach
     * Datum. Bei einem Athleten mit einem 5-km-Lauf neun Tage vor dem
     * Marathon pruefte sie damit die Zielzeit des 5ers — die passte, und
     * ueber den Marathon fiel kein Wort.
     */
    public function test_the_check_follows_the_active_plan_not_the_next_race(): void
    {
        $user = $this->athlete();
        $this->weeklyVolume($user, 25, 16);

        $marathon = $this->marathon($user, 3, 30, daysUntil: 40);

        // Ein B-Event davor, dessen Ziel voellig in Ordnung ist.
        Event::create([
            'user_id' => $user->id, 'name' => 'Sportcheck 5k', 'event_date' => now()->addDays(30),
            'race_distance' => '5km', 'priority' => 'B',
            'target_time_hours' => 0, 'target_time_minutes' => 20,
        ]);

        \App\Models\TrainingPlan::create([
            'user_id' => $user->id, 'event_id' => $marathon->id,
            'sessions' => [], 'is_active' => true,
        ]);

        $event = GoalCheckController::eventFor($user->fresh());

        $this->assertSame($marathon->id, $event->id, 'Gefragt wird nach dem Ziel, das den Plan bestimmt');
    }

    /** Ohne aktiven Plan zaehlt die Prioritaet vor dem Datum. */
    public function test_without_a_plan_the_a_event_wins(): void
    {
        $user = $this->athlete();
        $marathon = $this->marathon($user, 3, 30, daysUntil: 40);

        Event::create([
            'user_id' => $user->id, 'name' => 'Sportcheck 5k', 'event_date' => now()->addDays(30),
            'race_distance' => '5km', 'priority' => 'B',
            'target_time_hours' => 0, 'target_time_minutes' => 20,
        ]);

        $this->assertSame($marathon->id, GoalCheckController::eventFor($user->fresh())->id);
    }

    // ── Wann geschwiegen wird ────────────────────────────────────────────

    /** Kurz vor dem Rennen ist die Zielzeit Renntaktik, keine Planungsfrage. */
    public function test_no_question_in_the_last_two_weeks(): void
    {
        $user = $this->athlete();
        $this->weeklyVolume($user, 25, 16);
        $event = $this->marathon($user, 3, 30, daysUntil: 9);

        $this->assertNull($this->check($user, $event));
    }

    /** Ohne Laufdaten ist jede Aussage geraten. */
    public function test_without_running_data_there_is_no_verdict(): void
    {
        $user  = $this->athlete();
        $event = $this->marathon($user, 3, 30);

        $this->assertNull($this->check($user, $event));
    }

    /** Der Backyard hat keine Zielzeit, die man verfehlen könnte. */
    public function test_backyard_is_left_alone(): void
    {
        $user = $this->athlete();
        $this->weeklyVolume($user, 25, 16);

        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Backyard', 'event_date' => now()->addDays(60),
            'race_distance' => 'backyard_ultra', 'priority' => 'A',
            'target_time_hours' => 0, 'target_time_minutes' => 0, 'target_yards' => 12,
        ]);

        $this->assertNull($this->check($user, $event));
    }

    // ── Einmal fragen, nicht jeden Sonntag ───────────────────────────────

    public function test_a_decision_silences_the_question(): void
    {
        $user  = $this->athlete();
        $this->weeklyVolume($user, 25, 16);
        $event = $this->marathon($user, 3, 30);

        $this->assertTrue(GoalCheckController::isDue($event));

        $this->actingAs($user)->postJson(route('goal-check.confirm'))->assertOk();

        $this->assertFalse(GoalCheckController::isDue($event->fresh()), 'In derselben Woche nicht noch einmal');
    }

    /** Eine Entscheidung hält vier Wochen — danach ist die Lage eine andere. */
    public function test_the_question_returns_after_four_weeks(): void
    {
        $user  = $this->athlete();
        $this->weeklyVolume($user, 25, 16);
        $event = $this->marathon($user, 3, 30);

        $event->update([
            'goal_check_week'   => '2020-W01',
            'goal_confirmed_at' => now()->subWeeks(5),
        ]);

        $this->assertTrue(GoalCheckController::isDue($event->fresh()));
    }

    // ── Die Entscheidung wirkt ───────────────────────────────────────────

    public function test_adjusting_sets_the_new_target(): void
    {
        $user  = $this->athlete();
        $this->weeklyVolume($user, 25, 16);
        $event = $this->marathon($user, 3, 30);

        $this->actingAs($user)
            ->postJson(route('goal-check.adjust'), ['hours' => 3, 'minutes' => 45])
            ->assertOk();

        $event->refresh();

        $this->assertSame(3, $event->target_time_hours);
        $this->assertSame(45, $event->target_time_minutes);
        $this->assertNotNull($event->goal_confirmed_at, 'Ein gesetztes Ziel ist eine Entscheidung');
    }

    public function test_a_zero_target_is_refused(): void
    {
        $user  = $this->athlete();
        $this->weeklyVolume($user, 25, 16);
        $this->marathon($user, 3, 30);

        $this->actingAs($user)
            ->postJson(route('goal-check.adjust'), ['hours' => 0, 'minutes' => 0])
            ->assertStatus(422);
    }
}
