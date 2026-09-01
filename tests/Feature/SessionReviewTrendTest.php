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
 * Der Verlauf über Wochen im Review.
 *
 * Das Review sah genau eine Einheit und einen 90-Tage-Mittelwert. Damit
 * lässt sich sagen, ob der Lauf gut war — aber nicht, ob es aufwärts geht.
 * Und das ist die Frage, die einen Athleten über Wochen interessiert.
 */
class SessionReviewTrendTest extends TestCase
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
    }

    private function facts(TrainingSession $session): string
    {
        $job    = new \App\Jobs\GenerateSessionReviewJob($session->id);
        $method = new \ReflectionMethod($job, 'buildFacts');

        return $method->invoke($job, $session, app(TrainingLoadService::class));
    }

    /** Ein absolvierter Lauf mit Aktivität, $daysAgo Tage zurück. */
    private function completed(int $daysAgo, string $type, int $meters, int $seconds, ?int $hr = null): TrainingSession
    {
        $date = now()->subDays($daysAgo);

        $activity = Activity::create([
            'user_id'           => $this->user->id,
            'strava_id'         => random_int(1000000, 9999999),
            'name'              => 'Lauf',
            'type'              => 'Run',
            'start_date'        => $date,
            'distance'          => $meters,
            'moving_time'       => $seconds,
            'elapsed_time'      => $seconds,
            'average_speed'     => $meters / $seconds,
            'average_heartrate' => $hr,
        ]);

        return TrainingSession::create([
            'user_id'          => $this->user->id,
            'training_plan_id' => $this->plan->id,
            'event_id'         => $this->plan->event_id,
            'planned_date'     => $date->toDateString(),
            'type'             => $type,
            'title'            => 'Lauf',
            'description'      => '',
            'intensity'        => 'medium',
            'status'           => 'completed',
            'activity_id'      => $activity->id,
            'was_unplanned'    => false,
        ]);
    }

    public function test_the_weekly_volume_of_the_last_five_weeks_is_listed(): void
    {
        foreach ([2, 9, 16, 23, 30] as $daysAgo) {
            $this->completed($daysAgo, 'easy_run', 10000, 3000);
        }

        $today = $this->completed(0, 'easy_run', 12000, 3600);

        $facts = $this->facts($today);

        $this->assertStringContainsString('Wochenumfang', $facts);
        $this->assertStringContainsString('Lauf', $facts);
    }

    /** Wer den Umfang deutlich steigert, bekommt das benannt. */
    public function test_a_rising_volume_is_called_out(): void
    {
        // Zwei Wochen davor: je ~10 km. Zwei Wochen danach: je ~30 km.
        $this->completed(25, 'easy_run', 10000, 3000);
        $this->completed(18, 'easy_run', 10000, 3000);
        $this->completed(11, 'easy_run', 30000, 9000);
        $this->completed(4,  'easy_run', 30000, 9000);

        $facts = $this->facts($this->completed(0, 'easy_run', 12000, 3600));

        $this->assertStringContainsString('Umfang-Entwicklung: deutlich mehr', $facts);
    }

    public function test_the_adherence_of_the_last_four_weeks_is_counted(): void
    {
        $this->completed(3, 'easy_run', 10000, 3000);
        $this->completed(6, 'easy_run', 10000, 3000);

        TrainingSession::create([
            'user_id' => $this->user->id, 'training_plan_id' => $this->plan->id,
            'planned_date' => now()->subDays(9)->toDateString(), 'type' => 'interval',
            'title' => 'Intervalle', 'description' => '', 'intensity' => 'high',
            'status' => 'skipped', 'was_unplanned' => false,
        ]);

        $facts = $this->facts($this->completed(0, 'easy_run', 12000, 3600));

        $this->assertStringContainsString('Umsetzung der letzten 4 Wochen', $facts);
        $this->assertStringContainsString('1 ausgelassen', $facts);
    }

    /** Der Kernsatz: gleicher Puls, aber schneller als vor zwei Monaten. */
    public function test_the_development_of_this_session_type_is_computed(): void
    {
        // Früher (29–84 Tage): 6:00 min/km bei 150 bpm.
        $this->completed(70, 'tempo_run', 10000, 3600, 150);
        $this->completed(60, 'tempo_run', 10000, 3600, 150);

        // Jetzt (0–28 Tage): 5:30 min/km bei 150 bpm.
        $this->completed(20, 'tempo_run', 10000, 3300, 150);
        $this->completed(10, 'tempo_run', 10000, 3300, 150);

        $facts = $this->facts($this->completed(0, 'tempo_run', 10000, 3300, 150));

        $this->assertStringContainsString('Entwicklung bei Tempolauf', $facts);
        $this->assertStringContainsString('30 s/km schneller', $facts);
        $this->assertStringContainsString('praktisch unverändert', $facts);
    }

    /** Ohne genug Vergleichsdaten wird nichts behauptet. */
    public function test_no_trend_without_enough_data(): void
    {
        $this->completed(60, 'tempo_run', 10000, 3600, 150);

        $facts = $this->facts($this->completed(0, 'tempo_run', 10000, 3300, 150));

        $this->assertStringNotContainsString('Entwicklung bei', $facts);
    }

    /**
     * 359,97 s/km sind 6:00, nicht 5:00.
     *
     * Die Minute wurde abgeschnitten, die Sekunde gerundet — knapp unter
     * einer vollen Minute ergab das eine Minute zu wenig. Aufgefallen ist es
     * erst, als der Verlauf beide Zeitraeume nebeneinander stellte und die
     * Differenz nicht mehr zu den Zahlen passte.
     */
    public function test_a_pace_just_below_a_full_minute_keeps_its_minute(): void
    {
        $job    = new \App\Jobs\GenerateSessionReviewJob(0);
        $method = new \ReflectionMethod($job, 'secondsToPace');

        $this->assertSame('6:00', $method->invoke($job, 359.97));
        $this->assertSame('6:00', $method->invoke($job, 360.0));
        $this->assertSame('5:59', $method->invoke($job, 359.4));
        $this->assertSame('4:05', $method->invoke($job, 245.0));
    }

    /** Ein Athlet ohne Vorgeschichte bekommt keine leeren Zeilen. */
    public function test_a_first_session_produces_no_trend_lines(): void
    {
        $facts = $this->facts($this->completed(0, 'easy_run', 8000, 2700));

        $this->assertStringNotContainsString('Umfang-Entwicklung', $facts);
        $this->assertStringNotContainsString('Entwicklung bei', $facts);
    }
}
