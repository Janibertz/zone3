<?php

namespace Tests\Feature;

use App\Jobs\RegeneratePlanJob;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Der verabredete Zeitpunkt.
 *
 * Bis hierher gab es keinen. Der Plan änderte sich, wenn irgendetwas ihn
 * anstiess — der Athlet wusste nie, wann seine Woche feststeht. Jetzt wird
 * sie sonntags um 19:00 geschrieben, eine Stunde nach der
 * Verfügbarkeitsabfrage.
 */
class WeeklyPlanWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function athleteWithPlan(int $eventInDays = 40): TrainingPlan
    {
        $user = User::factory()->onboarded()->create();

        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays($eventInDays),
            'race_distance'       => 'half_marathon',
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 45,
        ]);

        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        return $plan;
    }

    private function onSunday(): void
    {
        $this->travelTo(now()->next(\Carbon\Carbon::SUNDAY)->setTime(19, 0));
    }

    // ── Wann er läuft ────────────────────────────────────────────────────

    public function test_the_week_is_written_on_sunday(): void
    {
        $this->onSunday();
        $plan = $this->athleteWithPlan();

        $this->artisan('plan:write-week')->assertSuccessful();

        $this->assertTrue((bool) $plan->refresh()->needs_plan_update);
        Queue::assertPushed(
            RegeneratePlanJob::class,
            fn ($job) => $job->reason === RegeneratePlanJob::REASON_WEEKLY,
        );
    }

    /** Wer den Sonntag verpasst, bekommt seine Woche am Montag. */
    public function test_monday_still_counts(): void
    {
        $this->travelTo(now()->next(\Carbon\Carbon::MONDAY)->setTime(6, 0));
        $this->athleteWithPlan();

        $this->artisan('plan:write-week')->assertSuccessful();

        Queue::assertPushed(RegeneratePlanJob::class);
    }

    /** Mitten in der Woche passiert nichts. */
    public function test_nothing_happens_on_a_wednesday(): void
    {
        $this->travelTo(now()->next(\Carbon\Carbon::WEDNESDAY)->setTime(19, 0));
        $this->athleteWithPlan();

        $this->artisan('plan:write-week')->assertSuccessful();

        Queue::assertNotPushed(RegeneratePlanJob::class);
    }

    /** Zum Testen und für den Notfall lässt er sich erzwingen. */
    public function test_force_runs_on_any_day(): void
    {
        $this->travelTo(now()->next(\Carbon\Carbon::WEDNESDAY)->setTime(10, 0));
        $this->athleteWithPlan();

        $this->artisan('plan:write-week', ['--force' => true])->assertSuccessful();

        Queue::assertPushed(RegeneratePlanJob::class);
    }

    // ── Wen er anfasst ───────────────────────────────────────────────────

    /** Ein Rennen in der Vergangenheit braucht keine Wochenplanung mehr. */
    public function test_a_past_event_is_skipped(): void
    {
        $this->onSunday();
        $this->athleteWithPlan(eventInDays: -5);

        $this->artisan('plan:write-week')->assertSuccessful();

        Queue::assertNotPushed(RegeneratePlanJob::class);
    }

    public function test_an_inactive_plan_is_skipped(): void
    {
        $this->onSunday();
        $this->athleteWithPlan()->forceFill(['is_active' => false])->save();

        $this->artisan('plan:write-week')->assertSuccessful();

        Queue::assertNotPushed(RegeneratePlanJob::class);
    }

    public function test_a_single_user_can_be_targeted(): void
    {
        $this->onSunday();
        $mine  = $this->athleteWithPlan();
        $other = $this->athleteWithPlan();

        $this->artisan('plan:write-week', ['--user' => [$mine->user_id]])->assertSuccessful();

        Queue::assertPushed(RegeneratePlanJob::class, fn ($job) => $job->userId === $mine->user_id);
        Queue::assertNotPushed(RegeneratePlanJob::class, fn ($job) => $job->userId === $other->user_id);
    }

    // ── Der Anlass wirkt richtig ─────────────────────────────────────────

    /**
     * Der Wochenschreiber ist der Zeitpunkt, an dem sich die Woche ändern
     * DARF. Würde er die nächsten Tage einfrieren, könnte er genau die Tage
     * nicht schreiben, um die es ihm geht.
     */
    public function test_the_weekly_reason_freezes_nothing(): void
    {
        $frozen = new \ReflectionMethod(RegeneratePlanJob::class, 'frozenThrough');

        $this->assertNull($frozen->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_WEEKLY)));
    }

    public function test_the_weekly_reason_bypasses_the_debounce(): void
    {
        $immediate = new \ReflectionMethod(RegeneratePlanJob::class, 'isImmediate');

        $this->assertTrue($immediate->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_WEEKLY)));
    }

    /** Im Änderungsverlauf steht "Wochenplanung", nicht "Automatisch angepasst". */
    public function test_the_revision_is_labelled_as_weekly_planning(): void
    {
        $label = new \ReflectionMethod(RegeneratePlanJob::class, 'revisionLabel');

        $this->assertSame('weekly', $label->invoke(new RegeneratePlanJob(1, RegeneratePlanJob::REASON_WEEKLY)));
        $this->assertSame('Wochenplanung', \App\Models\PlanRevision::TRIGGER_LABELS['weekly']);
    }
}
