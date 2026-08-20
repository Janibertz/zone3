<?php

namespace Tests\Feature;

use App\Jobs\CalculateThresholdPaceJob;
use App\Jobs\RegeneratePlanJob;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\AI\AthleteProfileService;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Die Schwellenpace.
 *
 * Der Ø-Puls einer Intervalleinheit liegt strukturell unter der LTHR —
 * Einlaufen und Trabpausen ziehen ihn nach unten. Wer die HF als Filter
 * benutzt, wirft damit ausgerechnet die aussagekräftigste Einheit weg.
 * Deshalb bekommt das Modell die einzelnen Belastungsintervalle.
 *
 * Und das Ergebnis geht nicht mehr ungeprüft in die Pace-Zonen.
 */
class ThresholdPaceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AthleteProfileService
    {
        return app(AthleteProfileService::class);
    }

    private function intervals(AthleteProfileService $svc, array $activity): array
    {
        $m = new \ReflectionMethod($svc, 'workIntervals');

        return $m->invoke($svc, $activity);
    }

    /** Eine Runde: Distanz in Metern, Dauer in Sekunden. */
    private function lap(int $meters, int $seconds, ?int $hr = null): array
    {
        return ['distance' => $meters, 'moving_time' => $seconds, 'average_heartrate' => $hr];
    }

    // ── Belastungsintervalle ─────────────────────────────────────────────

    /**
     * Nachbau der Einheit "4x 8 Min Schwelle": 11,42 km in 56 min, Ø 4:54/km
     * bei Ø157 bpm. Die Intervalle selbst liefen deutlich schneller und mit
     * deutlich höherem Puls — genau das war vorher unsichtbar.
     */
    public function test_the_work_intervals_of_a_threshold_session_are_found(): void
    {
        $activity = [
            'average_speed' => 11420 / 3360,          // Ø 4:54 min/km
            'laps'          => [
                $this->lap(2000, 660, 128),           // Einlaufen, 5:30/km
                $this->lap(2000, 480, 168),           // 8 min @ 4:00/km
                $this->lap(400,  150, 120),           // Trabpause
                $this->lap(2000, 486, 171),
                $this->lap(400,  150, 122),
                $this->lap(2000, 492, 172),
                $this->lap(400,  150, 124),
                $this->lap(1820, 492, 173),
                $this->lap(400,  300, 118),           // Auslaufen
            ],
        ];

        $work = $this->intervals($this->service(), $activity);

        $this->assertCount(4, $work, 'Vier Belastungen, nicht neun Runden');
        $this->assertStringContainsString('4:00', $work[0]);
        $this->assertStringContainsString('168 bpm', $work[0]);
        $this->assertStringContainsString('8 min', $work[0]);
    }

    /** Trabpausen sind zu kurz, um als Belastung zu zählen. */
    public function test_short_laps_are_not_work_intervals(): void
    {
        $activity = [
            'average_speed' => 10000 / 3000,          // Ø 5:00 min/km
            'laps'          => [
                $this->lap(400, 80,  170),            // 80 s — unter der Mindestdauer
                $this->lap(400, 80,  172),
                $this->lap(400, 80,  171),
                $this->lap(2000, 400, 168),           // echte Belastung, 3:20/km
                $this->lap(2000, 400, 169),
            ],
        ];

        $work = $this->intervals($this->service(), $activity);

        $this->assertCount(2, $work);
    }

    /** Ein gleichmäßiger Dauerlauf hat keine Belastungsintervalle. */
    public function test_a_steady_run_yields_nothing(): void
    {
        $activity = [
            'average_speed' => 10000 / 3000,
            'laps'          => [
                $this->lap(1000, 300, 140),
                $this->lap(1000, 301, 141),
                $this->lap(1000, 299, 142),
                $this->lap(1000, 300, 143),
            ],
        ];

        $this->assertSame([], $this->intervals($this->service(), $activity));
    }

    public function test_an_activity_without_laps_yields_nothing(): void
    {
        $this->assertSame([], $this->intervals($this->service(), ['average_speed' => 3.0]));
        $this->assertSame([], $this->intervals($this->service(), ['average_speed' => 3.0, 'laps' => null]));
    }

    // ── Der Job schützt den Bestand ──────────────────────────────────────

    private function athlete(float $currentPace = 5.0): User
    {
        $user = User::factory()->onboarded()->create();
        RunnerProfile::create([
            'user_id'              => $user->id,
            'threshold_speed'      => $currentPace,
            'threshold_heart_rate' => 172,
            'max_heart_rate'       => 190,
        ]);
        $user->unsetRelation('runnerProfile');

        return $user;
    }

    private function mockResult(?array $result): void
    {
        $this->mock(AthleteProfileService::class, function ($m) use ($result) {
            $m->shouldReceive('calculateThresholdPaceWithAI')->andReturn($result);
        });
        $this->mock(WebPushService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturnNull());
    }

    private function recalc(User $user): RunnerProfile
    {
        \App\Models\Activity::create([
            'user_id' => $user->id, 'strava_id' => 4242, 'name' => 'Lauf', 'type' => 'Run',
            'start_date' => now(), 'distance' => 10000, 'moving_time' => 3000,
            'elapsed_time' => 3000, 'average_speed' => 3.33,
        ]);

        (new CalculateThresholdPaceJob($user->id))->handle(
            app(AthleteProfileService::class),
            app(WebPushService::class),
        );

        return $user->runnerProfile()->first();
    }

    public function test_a_plausible_result_is_stored(): void
    {
        $user = $this->athlete(5.0);
        $this->mockResult(['pace' => 4.9, 'range' => '4:50-5:00', 'confidence' => 'high', 'evidence' => ['4x8 min']]);

        $profile = $this->recalc($user);

        $this->assertEqualsWithDelta(4.9, $profile->threshold_speed, 0.001);
        $this->assertSame('high', $profile->threshold_pace_history[0]['confidence']);
        $this->assertSame('4:54', $profile->threshold_pace_history[0]['pace_formatted']);
    }

    /** Ein Sprung um mehr als acht Prozent ist keine Formentwicklung. */
    public function test_an_implausible_jump_is_rejected(): void
    {
        $user = $this->athlete(5.0);
        $this->mockResult(['pace' => 8.5, 'range' => null, 'confidence' => 'high', 'evidence' => []]);

        $profile = $this->recalc($user);

        $this->assertEqualsWithDelta(5.0, $profile->threshold_speed, 0.001, 'Der Bestand bleibt');
        $this->assertEmpty($profile->threshold_pace_history ?? []);
        $this->assertFalse((bool) $profile->threshold_pace_calculating);
    }

    /** Ohne belastbaren Anker wird der vorhandene Wert nicht ausgetauscht. */
    public function test_a_low_confidence_result_does_not_overwrite(): void
    {
        $user = $this->athlete(5.0);
        $this->mockResult(['pace' => 4.8, 'range' => '4:40-5:10', 'confidence' => 'low', 'evidence' => []]);

        $this->assertEqualsWithDelta(5.0, $this->recalc($user)->threshold_speed, 0.001);
    }

    /** Wer noch gar keinen Wert hat, bekommt auch eine unsichere Schätzung. */
    public function test_the_first_estimate_is_accepted_even_when_uncertain(): void
    {
        $user = $this->athlete(0.0);
        $this->mockResult(['pace' => 5.5, 'range' => null, 'confidence' => 'low', 'evidence' => []]);

        $this->assertEqualsWithDelta(5.5, $this->recalc($user)->threshold_speed, 0.001);
    }

    // ── Der Plan zieht nach ──────────────────────────────────────────────

    private function withActivePlan(User $user): TrainingPlan
    {
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Zielrennen', 'event_date' => now()->addDays(40),
            'race_distance' => '10km', 'priority' => 'A',
            'target_time_hours' => 0, 'target_time_minutes' => 45,
        ]);

        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        return $plan;
    }

    /**
     * Die Zielpaces im Plan sind festgeschriebene Zeichenketten. Ohne
     * Neuberechnung liefen Profil und Plan auseinander.
     */
    public function test_a_meaningful_change_flags_the_plan(): void
    {
        Queue::fake();

        $user = $this->athlete(5.0);
        $plan = $this->withActivePlan($user);
        $this->mockResult(['pace' => 4.85, 'range' => null, 'confidence' => 'high', 'evidence' => []]);

        $this->recalc($user);

        $this->assertTrue($plan->refresh()->needs_plan_update);
        Queue::assertPushed(RegeneratePlanJob::class);
    }

    /** Zwei Sekunden Unterschied rechtfertigen keinen neuen Plan. */
    public function test_a_marginal_change_leaves_the_plan_alone(): void
    {
        Queue::fake();

        $user = $this->athlete(5.0);
        $plan = $this->withActivePlan($user);
        $this->mockResult(['pace' => 5.005, 'range' => null, 'confidence' => 'high', 'evidence' => []]);

        $this->recalc($user);

        $this->assertFalse((bool) $plan->refresh()->needs_plan_update);
        Queue::assertNotPushed(RegeneratePlanJob::class);
    }
}
