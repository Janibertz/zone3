<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\PlanInterview;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\PlanContextBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gewünscht: „Ich möchte zum Beispiel erst den Plan später starten … Es soll
 * nichts im Plan vor dem Startdatum stehen. Das Interview soll jedesmal
 * kommen wenn ich einen neuen Plan erstellen will."
 *
 * Das Startdatum hängt am Event (`plan_start_date`), und `Event::planStartsOn()`
 * ist die eine Stelle, von der Gerüst, Prompt und `plan:auto-update` ihr
 * Fenster rechnen.
 */
class PlanStartDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25 10:00:00'); // Sonntag
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function athlete(): User
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->updateOrCreate([], [
            'threshold_speed'     => 4.5,
            'weekly_availability' => collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
                ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => 60]])->all(),
        ]);

        return $user->refresh();
    }

    private function event(User $user, array $extra = []): Event
    {
        return Event::create($extra + [
            'user_id' => $user->id, 'name' => 'Halbmarathon', 'event_date' => '2027-03-14',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
    }

    public function test_the_interview_offers_a_start_date(): void
    {
        $user  = $this->athlete();
        $event = $this->event($user);

        $this->actingAs($user)
            ->get(route('plan-interview.show', ['event' => $event->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('event.start_min', '2026-10-25')
                ->where('event.start_max', '2027-03-13')
                ->where('event.start_date', '2026-10-25'));
    }

    public function test_a_later_start_is_stored_and_the_plan_is_built_right_away(): void
    {
        $user  = $this->athlete();
        $event = $this->event($user);

        $this->actingAs($user)
            ->post(route('plan-interview.store'), ['event_id' => $event->id, 'plan_start_date' => '2026-11-09'])
            ->assertRedirect(route('events.plan.show', ['event' => $event->id, 'generate' => 1]));

        $this->assertSame('2026-11-09', $event->fresh()->plan_start_date->toDateString());
    }

    /** Heute als Start ist kein Startdatum — sonst stünde morgen ein vergangenes Datum da. */
    public function test_starting_today_stores_nothing(): void
    {
        $user  = $this->athlete();
        $event = $this->event($user, ['plan_start_date' => '2026-11-30']);

        $this->actingAs($user)
            ->post(route('plan-interview.store'), ['event_id' => $event->id, 'plan_start_date' => '2026-10-25']);

        $this->assertNull($event->fresh()->plan_start_date);
    }

    public function test_a_start_in_the_past_is_rejected(): void
    {
        $user  = $this->athlete();
        $event = $this->event($user);

        $this->actingAs($user)
            ->post(route('plan-interview.store'), ['event_id' => $event->id, 'plan_start_date' => '2026-10-20'])
            ->assertSessionHasErrors('plan_start_date');

        $this->assertSame(0, PlanInterview::count());
    }

    public function test_a_start_on_race_day_is_rejected_before_anything_is_saved(): void
    {
        $user  = $this->athlete();
        $event = $this->event($user);

        $this->actingAs($user)
            ->post(route('plan-interview.store'), ['event_id' => $event->id, 'plan_start_date' => '2027-03-14'])
            ->assertSessionHasErrors('plan_start_date');

        $this->assertSame(0, PlanInterview::count(), 'Ein korrigiertes Datum darf kein zweites Interview hinterlassen');
        $this->assertNull($event->fresh()->plan_start_date);
    }

    /** Der Kern: vor dem Start steht nichts im Gerüst, und das Fenster ist trotzdem 14 Tage lang. */
    public function test_the_plan_window_begins_at_the_start_date(): void
    {
        $user  = $this->athlete();
        $event = $this->event($user, ['plan_start_date' => '2026-11-09']);

        $context = app(PlanContextBuilder::class)->build($user, $event);

        $this->assertSame('2026-11-09', $context->windowFrom->toDateString());
        $this->assertSame('2026-11-22', $context->windowTo->toDateString());

        $dates = array_keys($context->skeleton['days']);
        $this->assertSame('2026-11-09', min($dates));
        $this->assertEmpty(array_filter($dates, fn ($d) => $d < '2026-11-09'));
    }

    /** Ein vergangenes Startdatum zählt nicht mehr: der Plan rollt ab heute weiter. */
    public function test_a_start_date_in_the_past_means_today(): void
    {
        $user  = $this->athlete();
        $event = $this->event($user, ['plan_start_date' => '2026-10-01']);

        $this->assertSame('2026-10-25', $event->planStartsOn()->toDateString());
        $this->assertSame(140, $event->daysFromPlanStart());
    }

    /** Kurz vor dem Rennen endet das Fenster am Renntag, auch mit späterem Start. */
    public function test_the_window_still_ends_on_race_day(): void
    {
        $user  = $this->athlete();
        $event = $this->event($user, ['event_date' => '2026-11-15', 'race_distance' => '10km', 'plan_start_date' => '2026-11-09']);

        $context = app(PlanContextBuilder::class)->build($user, $event);

        $this->assertSame('2026-11-09', $context->windowFrom->toDateString());
        $this->assertSame('2026-11-15', $context->windowTo->toDateString());
    }

    /**
     * `plan:auto-update` rechnete sein Fenster ab heute. Ein Plan, der erst in
     * zwei Wochen beginnt, sah dort nur Lücken und wurde alle zwölf Stunden
     * neu gebaut.
     */
    public function test_auto_update_sees_no_gap_before_the_start(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $user  = $this->athlete();
        $event = $this->event($user, ['plan_start_date' => '2026-11-09']);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        for ($d = Carbon::parse('2026-11-09'); $d->lte('2026-11-22'); $d->addDay()) {
            TrainingSession::create([
                'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
                'planned_date' => $d->toDateString(), 'type' => 'easy_run', 'title' => 'Locker',
                'description' => '', 'intensity' => 'low', 'status' => 'planned',
            ]);
        }

        $plan->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

        $this->artisan('plan:auto-update')->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_the_plan_page_announces_a_later_start(): void
    {
        $user  = $this->athlete();
        $event = $this->event($user, ['plan_start_date' => '2026-11-09']);

        $this->actingAs($user)
            ->get(route('events.plan.show', $event->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('event.plan_start_label', 'Mo, 9. November'));
    }
}
