<?php

namespace Tests\Feature;

use App\Jobs\RegeneratePlanJob;
use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Der Plan steht.
 *
 * Sieben Stellen lösten eine Neuberechnung aus, und jede davon löschte alle
 * geplanten Einheiten und liess sie vom Modell komplett neu erfinden. Zwei
 * dieser Auslöser waren "der Athlet hat getan, was im Plan stand" — der
 * denkbar schlechteste Grund, einen Plan umzuwerfen. Weil ein Sprachmodell
 * nicht deterministisch ist, kam dabei jedes Mal etwas anderes heraus:
 * Ruhetage verschwanden, ein Schwellentraining wurde zu zwanzig lockeren
 * Minuten.
 */
class PlanStabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->onboarded()->create();

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

    private function planned(int $inDays = 0, string $type = 'tempo_run'): TrainingSession
    {
        return TrainingSession::create([
            'user_id'          => $this->user->id,
            'training_plan_id' => $this->plan->id,
            'event_id'         => $this->plan->event_id,
            'planned_date'     => now()->addDays($inDays)->toDateString(),
            'type'             => $type,
            'title'            => 'Einheit',
            'description'      => '',
            'intensity'        => 'medium',
            'status'           => 'planned',
        ]);
    }

    // ── Was den Plan NICHT mehr umwirft ──────────────────────────────────

    /** Der Kern: eine abgehakte Einheit ist kein Anlass. */
    public function test_completing_a_session_does_not_touch_the_plan(): void
    {
        $session = $this->planned();

        $this->actingAs($this->user)
            ->patchJson(route('training-sessions.complete', $session))
            ->assertOk();

        $this->assertFalse((bool) $this->plan->refresh()->needs_plan_update);
        Queue::assertNotPushed(RegeneratePlanJob::class);
    }

    /** Das Review kommt trotzdem — nur der Plan bleibt stehen. */
    public function test_completing_still_triggers_the_review(): void
    {
        $session = $this->planned();

        $this->actingAs($this->user)
            ->patchJson(route('training-sessions.complete', $session))
            ->assertOk();

        Queue::assertPushed(\App\Jobs\GenerateSessionReviewJob::class);
    }

    // ── Was ihn weiterhin umwirft ────────────────────────────────────────

    /** Eine ausgelassene Einheit ist ein echter Anlass. */
    public function test_skipping_a_session_still_adjusts_the_plan(): void
    {
        $session = $this->planned(inDays: 1);

        $this->actingAs($this->user)
            ->patchJson(route('training-sessions.skip', $session), ['reason' => 'Keine Zeit'])
            ->assertOk();

        $this->assertTrue((bool) $this->plan->refresh()->needs_plan_update);
        Queue::assertPushed(
            RegeneratePlanJob::class,
            fn ($job) => $job->reason === RegeneratePlanJob::REASON_SKIP,
        );
    }

    // ── Der Anlass entscheidet über die Sperre ───────────────────────────

    public function test_an_athlete_triggered_reason_is_immediate(): void
    {
        $immediate = new \ReflectionMethod(RegeneratePlanJob::class, 'isImmediate');

        $this->assertTrue($immediate->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_SKIP)));
        $this->assertTrue($immediate->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_MANUAL)));
        $this->assertTrue($immediate->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_AVAILABILITY)));
    }

    public function test_an_automatic_reason_is_not(): void
    {
        $immediate = new \ReflectionMethod(RegeneratePlanJob::class, 'isImmediate');

        $this->assertFalse($immediate->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_AUTO)));
        $this->assertFalse($immediate->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_THRESHOLD)));
        $this->assertFalse($immediate->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_GAP)));
    }

    // ── Der Freeze-Horizont ──────────────────────────────────────────────

    /**
     * Wer sich auf sein Schwellentraining am Donnerstag eingestellt hat,
     * soll es am Donnerstag auch vorfinden.
     */
    public function test_an_automatic_run_freezes_the_next_days(): void
    {
        $frozen = new \ReflectionMethod(RegeneratePlanJob::class, 'frozenThrough');

        $through = $frozen->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_AUTO));

        $this->assertSame(now()->addDays(RegeneratePlanJob::FREEZE_DAYS)->toDateString(), $through);
    }

    /** Wer selbst um die Änderung bittet, bekommt sie auch für heute. */
    public function test_an_athlete_triggered_run_freezes_nothing(): void
    {
        $frozen = new \ReflectionMethod(RegeneratePlanJob::class, 'frozenThrough');

        $this->assertNull($frozen->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_SKIP)));
        $this->assertNull($frozen->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_AVAILABILITY)));
    }

    // ── Der Strava-Import ────────────────────────────────────────────────

    /**
     * Ein Lauf, der zu einer geplanten Einheit passt, ist kein Anlass —
     * das war der zweite der beiden schlechten Auslöser.
     */
    public function test_an_activity_matching_the_plan_does_not_flag_it(): void
    {
        $session = $this->planned();

        $activity = Activity::create([
            'user_id'       => $this->user->id,
            'strava_id'     => 555001,
            'name'          => 'Tempolauf',
            'type'          => 'Run',
            'start_date'    => now(),
            'distance'      => 12000,
            'moving_time'   => 3300,
            'elapsed_time'  => 3300,
            'average_speed' => 12000 / 3300,
        ]);

        app(\App\Services\StravaImportService::class)
            ->matchActivityToSession($this->user->id, $activity);

        $this->assertSame('completed', $session->refresh()->status);
        $this->assertFalse((bool) $this->plan->refresh()->needs_plan_update, 'Der Plan bleibt stehen');
    }
}
