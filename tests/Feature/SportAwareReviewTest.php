<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingLoadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Coach muss wissen, welche Sportart er vor sich hat.
 *
 * Der Strava-Import kannte nur "Kraft" und "alles andere". Alles andere —
 * Schwimmen, Radfahren, Yoga — wurde als `easy_run` gespeichert. Der Coach
 * las den Trainingstyp, sah "Lockerer Lauf" und fragte den Athleten nach
 * seinem Lauf, obwohl der geschwommen war. Die Sportart stand die ganze
 * Zeit korrekt in `activities.type` und wurde nie weitergereicht.
 */
class SportAwareReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->onboarded()->create();

        $event = Event::create([
            'user_id'             => $this->user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(60),
            'race_distance'       => 'half_marathon',
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 40,
        ]);

        $this->plan = TrainingPlan::create([
            'user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => [],
        ]);
        $this->plan->forceFill(['is_active' => true])->save();
    }

    private function facts(TrainingSession $session): string
    {
        $job    = new \App\Jobs\GenerateSessionReviewJob($session->id);
        $method = new \ReflectionMethod($job, 'buildFacts');

        return $method->invoke($job, $session, app(TrainingLoadService::class));
    }

    private function label(TrainingSession $session): string
    {
        $job    = new \App\Jobs\GenerateSessionReviewJob($session->id);
        $method = new \ReflectionMethod($job, 'sessionLabel');

        return $method->invoke($job, $session);
    }

    private function entry(?string $sport, int $meters, int $seconds, int $daysAgo = 0, string $type = 'easy_run'): TrainingSession
    {
        $date = now()->subDays($daysAgo);

        $activity = Activity::create([
            'user_id'           => $this->user->id,
            'strava_id'         => random_int(1000000, 9999999),
            'name'              => $sport === 'Swim' ? 'Bahnen ziehen' : 'Einheit',
            'type'              => $sport ?? 'Run',
            'start_date'        => $date,
            'distance'          => $meters,
            'moving_time'       => $seconds,
            'elapsed_time'      => $seconds,
            'average_speed'     => $meters / $seconds,
            'average_heartrate' => 140,
        ]);

        return TrainingSession::create([
            'user_id'          => $this->user->id,
            'training_plan_id' => $this->plan->id,
            'event_id'         => $this->plan->event_id,
            'planned_date'     => $date->toDateString(),
            'type'             => $type,
            'sport_type'       => $sport,
            'title'            => $activity->name,
            'description'      => '',
            'intensity'        => 'medium',
            'status'           => 'completed',
            'activity_id'      => $activity->id,
            'was_unplanned'    => $sport !== null && $sport !== 'Run',
        ]);
    }

    // ── Das Modell ───────────────────────────────────────────────────────

    public function test_a_missing_sport_type_means_running(): void
    {
        $session = new TrainingSession(['type' => 'easy_run']);

        $this->assertTrue($session->isRun());
        $this->assertSame('Lauf', $session->sportLabel());
    }

    public function test_a_swim_is_not_a_run(): void
    {
        $session = new TrainingSession(['type' => 'easy_run', 'sport_type' => 'Swim']);

        $this->assertFalse($session->isRun());
        $this->assertSame('Schwimmen', $session->sportLabel());
    }

    public function test_a_trail_run_still_counts_as_running(): void
    {
        $this->assertTrue((new TrainingSession(['sport_type' => 'TrailRun']))->isRun());
    }

    /** Eine unbekannte Sportart wird durchgereicht, nicht verschluckt. */
    public function test_an_unknown_sport_keeps_its_name(): void
    {
        $session = new TrainingSession(['sport_type' => 'Kitesurf']);

        $this->assertFalse($session->isRun());
        $this->assertSame('Kitesurf', $session->sportLabel());
    }

    // ── Das Review ───────────────────────────────────────────────────────

    public function test_the_label_names_the_sport_instead_of_the_placeholder(): void
    {
        $swim = $this->entry('Swim', 1500, 2400);

        $this->assertStringContainsString('Schwimmen', $this->label($swim));
        $this->assertStringNotContainsString('Lockerer Lauf', $this->label($swim));
    }

    public function test_the_facts_open_with_the_sport(): void
    {
        $facts = $this->facts($this->entry('Swim', 1500, 2400));

        $this->assertStringContainsString('SPORTART: Schwimmen', $facts);
        $this->assertStringContainsString('KEIN Lauf', $facts);
    }

    /** Eine Pace in min/km ist beim Schwimmen keine Größe, die der Coach einordnen kann. */
    public function test_no_running_pace_is_reported_for_a_swim(): void
    {
        $facts = $this->facts($this->entry('Swim', 1500, 2400));

        $this->assertStringNotContainsString('Ø-Pace', $facts);
    }

    public function test_a_normal_run_still_reports_its_pace(): void
    {
        $facts = $this->facts($this->entry(null, 10000, 3000));

        $this->assertStringContainsString('Ø-Pace', $facts);
        $this->assertStringNotContainsString('SPORTART:', $facts);
    }

    /** Die Wochenkilometer sind Laufkilometer — das muss dabeistehen. */
    public function test_the_swim_is_marked_as_not_counting_towards_running_volume(): void
    {
        $this->entry(null, 10000, 3000, daysAgo: 3);

        $facts = $this->facts($this->entry('Swim', 1500, 2400));

        $this->assertStringContainsString('LAUF-Kilometer', $facts);
    }

    // ── Keine Vermischung der Vergleiche ─────────────────────────────────

    /**
     * Der Kern der Datenverschmutzung: eine Schwimmeinheit trägt den
     * Platzhalter-Typ `easy_run` und wäre gegen echte lockere Läufe
     * verglichen worden.
     */
    public function test_a_swim_gets_no_run_baseline(): void
    {
        foreach ([10, 20, 30] as $daysAgo) {
            $this->entry(null, 10000, 3000, $daysAgo);
        }

        $facts = $this->facts($this->entry('Swim', 1500, 2400));

        $this->assertStringNotContainsString('Dein Normalwert', $facts);
        $this->assertStringNotContainsString('Entwicklung bei', $facts);
    }

    /** Und umgekehrt: Schwimmeinheiten verfälschen den Laufschnitt nicht. */
    public function test_swims_do_not_pollute_the_run_baseline(): void
    {
        // Zwei echte Läufe bei 5:00/km …
        $this->entry(null, 10000, 3000, 10);
        $this->entry(null, 10000, 3000, 20);

        // … und zwei Schwimmeinheiten, die mit demselben Platzhalter-Typ
        // gespeichert sind und rechnerisch bei 26:40/km lägen.
        $this->entry('Swim', 1500, 2400, 12);
        $this->entry('Swim', 1500, 2400, 22);

        $facts = $this->facts($this->entry(null, 10000, 3000));

        $this->assertStringContainsString('Dein Normalwert', $facts);
        $this->assertStringContainsString('5:00 min/km', $facts, 'Der Schnitt bleibt bei den Läufen');
    }
}
