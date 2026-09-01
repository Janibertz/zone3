<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingLoadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sportarten sauber getrennt.
 *
 * Laufen ist Laufen, Schwimmen ist Schwimmen, Rad ist Rad. Der Import
 * kannte nur "Kraft" und "alles andere", und alles andere bekam den
 * Lauf-Platzhalter `easy_run`. Das lief bis in die Belastungsrechnung
 * durch: dort wurde jede Aktivität über die Laufpace bewertet, und weil
 * ein Rad schneller fährt als ein Mensch läuft, schlug der
 * Intensitätsfaktor am oberen Anschlag an.
 */
class SportSeparationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->onboarded()->create();

        // Schwellenpace 4:22 min/km, LTHR 172 — Jans echte Werte.
        RunnerProfile::create([
            'user_id'              => $this->user->id,
            'threshold_speed'      => 4 + 22 / 60,
            'threshold_heart_rate' => 172,
        ]);

        $event = Event::create([
            'user_id'             => $this->user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(40),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);

        $this->plan = TrainingPlan::create([
            'user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => [],
        ]);
        $this->plan->forceFill(['is_active' => true])->save();
    }

    private function activity(string $type, int $meters, int $seconds, ?int $hr = null): Activity
    {
        return Activity::create([
            'user_id'           => $this->user->id,
            'strava_id'         => random_int(1000000, 9999999),
            'name'              => $type,
            'type'              => $type,
            'start_date'        => now()->subDay(),
            'distance'          => $meters,
            'moving_time'       => $seconds,
            'elapsed_time'      => $seconds,
            'average_speed'     => $meters / $seconds,
            'average_heartrate' => $hr,
        ]);
    }

    private function import(Activity $activity): void
    {
        app(\App\Services\StravaImportService::class)
            ->matchActivityToSession($this->user->id, $activity);
    }

    private function tss(Activity $activity): float
    {
        $method = new \ReflectionMethod(TrainingLoadService::class, 'activityTSS');

        return $method->invoke(app(TrainingLoadService::class), $activity, (4 + 22 / 60) * 60, 172);
    }

    // ── Der Import ordnet richtig zu ─────────────────────────────────────

    public function test_a_swim_becomes_cross_training(): void
    {
        $this->import($this->activity('Swim', 1500, 2400, 140));

        $session = TrainingSession::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame('cross_training', $session->type);
        $this->assertSame('Swim', $session->sport_type);
        $this->assertSame('Schwimmen', $session->sportLabel());
        $this->assertFalse($session->isRun());
    }

    public function test_a_ride_becomes_cross_training(): void
    {
        $this->import($this->activity('Ride', 30000, 3600, 128));

        $session = TrainingSession::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame('cross_training', $session->type);
        $this->assertSame('Radfahren', $session->sportLabel());
    }

    /** Ein Lauf bleibt ein Lauf. */
    public function test_a_run_stays_a_run(): void
    {
        $this->import($this->activity('Run', 10000, 3000, 150));

        $session = TrainingSession::where('user_id', $this->user->id)->firstOrFail();

        $this->assertTrue($session->isRun());
        $this->assertFalse($session->isCrossTraining());
    }

    /** Krafttraining behält seinen eigenen Typ. */
    public function test_weight_training_stays_strength(): void
    {
        $this->import($this->activity('WeightTraining', 0, 1800, 110));

        $session = TrainingSession::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame('strength', $session->type);
        $this->assertSame('WeightTraining', $session->sport_type);
    }

    /** Schwimmkilometer sind keine Laufkilometer. */
    public function test_a_swim_carries_no_distance(): void
    {
        $this->import($this->activity('Swim', 1500, 2400, 140));

        $this->assertNull(TrainingSession::where('user_id', $this->user->id)->firstOrFail()->distance_km);
    }

    // ── Die Belastungsrechnung ───────────────────────────────────────────

    /**
     * Der gravierendste der beiden Fehler. Die Pace-Formel lief über jede
     * Aktivität, und der Intensitätsfaktor ist bei 1,5 gedeckelt: eine
     * Radfahrt von 25 Minuten kam damit auf 94 TSS, ein Zehner-Lauf auf 64.
     */
    public function test_a_ride_is_no_longer_rated_by_running_pace(): void
    {
        $run  = $this->tss($this->activity('Run',  10000, 3000, 150));
        $ride = $this->tss($this->activity('Ride', 30000, 3600, 128));

        $this->assertLessThan(
            $run * 1.5,
            $ride,
            'Eine Stunde Rad darf nicht ein Vielfaches eines Zehn-Kilometer-Laufs sein',
        );
    }

    /** Und in der Gegenrichtung: Schwimmen zählte praktisch gar nicht. */
    public function test_a_swim_is_no_longer_worth_almost_nothing(): void
    {
        $swim = $this->tss($this->activity('Swim', 1500, 2400, 140));

        $this->assertGreaterThan(20, $swim, '40 Minuten Schwimmen sind mehr als ein Spaziergang');
    }

    /** Ohne Pulsdaten bleibt nur die Dauer — aber keine erfundene Pace. */
    public function test_a_ride_without_heart_rate_falls_back_to_duration(): void
    {
        $ride = $this->tss($this->activity('Ride', 30000, 3600, null));

        $this->assertSame(60.0, $ride);
    }

    /** Beim Laufen bleibt die Pace-Rechnung — sie ist dort die genaueste. */
    public function test_running_still_uses_pace(): void
    {
        $fast = $this->tss($this->activity('Run', 10000, 2400, 165));   // 4:00/km
        $slow = $this->tss($this->activity('Run', 10000, 3600, 130));   // 6:00/km

        $this->assertGreaterThan($slow, $fast, 'Schneller laufen ist mehr Belastung');
    }

    // ── Auswertungen fassen nur Läufe an ─────────────────────────────────

    public function test_the_runs_only_scope_excludes_cross_training(): void
    {
        $this->import($this->activity('Run',  10000, 3000, 150));
        $this->import($this->activity('Swim',  1500, 2400, 140));
        $this->import($this->activity('Ride', 30000, 3600, 128));

        $this->assertSame(3, TrainingSession::where('user_id', $this->user->id)->count());
        $this->assertSame(1, TrainingSession::where('user_id', $this->user->id)->runsOnly()->count());
    }

    /**
     * Auch mit dem alten Lauf-Platzhalter im Typ darf eine Radfahrt nicht
     * als Lauf durchgehen — die Sportart entscheidet mit.
     */
    public function test_an_old_placeholder_row_is_still_not_a_run(): void
    {
        $session = new TrainingSession(['type' => 'easy_run', 'sport_type' => 'Ride']);

        $this->assertFalse($session->isRun());
    }

    // ── Die Anzeige ──────────────────────────────────────────────────────

    public function test_the_plan_page_names_the_sport(): void
    {
        $this->import($this->activity('Swim', 1500, 2400, 140));

        $this->actingAs($this->user)
            ->get(route('events.plan.show', $this->plan->event_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sessions.0.sport_label', 'Schwimmen'));
    }

    public function test_a_run_gets_no_sport_label(): void
    {
        $this->import($this->activity('Run', 10000, 3000, 150));

        $this->actingAs($this->user)
            ->get(route('events.plan.show', $this->plan->event_id))
            ->assertInertia(fn ($page) => $page->where('sessions.0.sport_label', null));
    }
}
