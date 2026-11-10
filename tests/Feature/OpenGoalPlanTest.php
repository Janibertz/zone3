<?php

namespace Tests\Feature;

use App\Console\Commands\RollOpenBlocks;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\PeriodizationService;
use App\Services\Plan\OpenGoalHandover;
use App\Services\PlanContextBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Plan ohne Rennen (Jan, 10.11.2026): Blöcke zu zwölf Wochen im 3:1,
 * am Ende ein 5-km-Test, der sich abschalten lässt, danach der nächste
 * Block von selbst. Ein Rennen übernimmt ab dem nächsten Montag.
 */
class OpenGoalPlanTest extends TestCase
{
    use RefreshDatabase;

    /** Ein Dienstag. */
    private const TODAY = '2026-11-10';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::TODAY));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function open(array $overrides = [], ?User $user = null): Event
    {
        $user ??= User::factory()->create(['onboarding_completed_at' => now()]);

        return Event::create(array_merge(
            Event::openAttributes('speed', '5km', true),
            ['user_id' => $user->id],
            $overrides,
        ));
    }

    private function grid(): array
    {
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $d) {
            $days[$d] = ['available' => $d !== 'thursday', 'duration_min' => $d === 'sunday' ? 120 : 60];
        }

        return $days;
    }

    // ── Block ────────────────────────────────────────────────────────────

    public function test_a_block_ends_on_the_sunday_after_twelve_weeks(): void
    {
        $attrs = Event::openAttributes('speed', '10km', true, '2026-11-11');

        $this->assertSame('2027-01-31', $attrs['event_date']);
        $this->assertSame('2026-11-11', $attrs['plan_start_date']);
        $this->assertSame('10km', $attrs['race_distance']);
        $this->assertSame('Schneller werden auf 10 km', $attrs['name']);

        // Ohne Startdatum: sofort, ohne gespeichertes Datum.
        $now = Event::openAttributes('base', '5km', false);
        $this->assertNull($now['plan_start_date']);
        $this->assertSame('10km', $now['race_distance'], 'Nur „schneller werden" wählt die Distanz.');
        $this->assertSame('2027-01-31', $now['event_date']);
    }

    public function test_the_next_block_starts_by_itself(): void
    {
        $event = $this->open(['event_date' => '2026-11-08', 'block_number' => 1]);

        $this->assertTrue($event->rollOpenBlock());
        $this->assertSame('2027-01-31', $event->fresh()->event_date->toDateString());
        $this->assertSame(2, $event->fresh()->block_number);
        $this->assertFalse($event->fresh()->rollOpenBlock(), 'Ein laufender Block rollt nicht.');
    }

    public function test_only_goals_with_an_active_plan_roll(): void
    {
        $idle   = $this->open(['event_date' => '2026-11-08']);
        $active = $this->open(['event_date' => '2026-11-08']);
        TrainingPlan::create(['user_id' => $active->user_id, 'event_id' => $active->id, 'sessions' => [], 'is_active' => true]);

        $this->assertSame(1, RollOpenBlocks::rollAll());
        $this->assertSame('2026-11-08', $idle->fresh()->event_date->toDateString());
        $this->assertSame('2027-01-31', $active->fresh()->event_date->toDateString());
    }

    // ── Periodisierung ───────────────────────────────────────────────────

    public function test_three_load_weeks_then_one_recovery_and_a_test_week_at_the_end(): void
    {
        $event = $this->open(['event_date' => '2027-01-31']);
        $p     = app(PeriodizationService::class);
        $start = CarbonImmutable::parse('2026-11-09');

        $recovery = [];
        for ($w = 0; $w < 12; $w++) {
            $week = $p->forWeek($event, $start->addWeeks($w));
            if ($week['recovery']) {
                $recovery[] = $w + 1;
            }
        }

        $this->assertSame([4, 8], $recovery);
        $this->assertSame('Testwoche', $p->forWeek($event, $start->addWeeks(11))['label']);

        $event->open_test = false;
        $this->assertTrue($p->forWeek($event, $start->addWeeks(11))['recovery'], 'Ohne Test ist Woche 12 Entlastung.');
    }

    public function test_keeping_form_does_not_progress(): void
    {
        $this->assertSame(0.0, PeriodizationService::progressionPct($this->open(['open_focus' => 'maintain'])));
        $this->assertGreaterThan(0.0, PeriodizationService::progressionPct($this->open(['open_focus' => 'base'])));
    }

    // ── Gerüst ───────────────────────────────────────────────────────────

    private function skeletonDay(Event $event, string $date): array
    {
        $event->user->runnerProfile()->create(['weekly_availability' => $this->grid()]);
        $context = app(PlanContextBuilder::class)->build($event->user->fresh(), $event->fresh());

        return collect($context->skeleton['days'])->firstWhere('date', $date) ?? [];
    }

    public function test_the_block_ends_with_a_five_km_test(): void
    {
        $event = $this->open(['event_date' => '2026-11-22']);
        $day   = $this->skeletonDay($event, '2026-11-22');

        $test = collect($day['slots'] ?? [])->firstWhere('type', 'race_prep');
        $this->assertNotNull($test, 'Am Blockende steht der Test.');
        $this->assertSame('5-km-Test', $test['label']);
    }

    public function test_without_the_test_the_block_just_ends(): void
    {
        $event = $this->open(['event_date' => '2026-11-22', 'open_test' => false]);
        $day   = $this->skeletonDay($event, '2026-11-22');

        $this->assertNull(collect($day['slots'] ?? [])->firstWhere('type', 'race_prep'));
    }

    // ── Übergabe an ein Rennen ───────────────────────────────────────────

    public function test_a_race_takes_over_from_next_monday(): void
    {
        $open = $this->open();
        $plan = TrainingPlan::create(['user_id' => $open->user_id, 'event_id' => $open->id, 'sessions' => [], 'is_active' => true]);

        $session = fn (string $date) => TrainingSession::create([
            'user_id' => $open->user_id, 'training_plan_id' => $plan->id, 'event_id' => $open->id,
            'planned_date' => $date, 'type' => 'easy_run', 'title' => 'Locker', 'status' => 'planned', 'intensity' => 'easy',
        ]);
        $thisWeek = $session('2026-11-14');
        $nextWeek = $session('2026-11-17');

        $race = Event::create([
            'user_id' => $open->user_id, 'name' => 'Halbmarathon', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 30,
        ]);

        $this->assertNotNull(OpenGoalHandover::activeOpenPlan($open->user_id, $race->id));
        $monday = app(OpenGoalHandover::class)->handOver($plan, $race);

        $this->assertSame('2026-11-16', $monday->toDateString());
        $this->assertSame('2026-11-16', $race->fresh()->plan_start_date->toDateString());
        $this->assertFalse($plan->fresh()->is_active);
        $this->assertNull(TrainingSession::find($nextWeek->id), 'Ab Montag gilt der Rennplan.');

        $kept = TrainingSession::find($thisWeek->id);
        $this->assertNull($kept->training_plan_id);
        $this->assertNotNull($kept->pinned_at, 'Die laufende Woche bleibt und übersteht Neuberechnungen.');

        $this->assertSame('2026-11-15', $open->fresh()->event_date->toDateString());
        $this->assertFalse(RollOpenBlocks::rollAll() > 0);
    }

    public function test_an_open_goal_does_not_block_a_race_plan(): void
    {
        $open = $this->open();
        TrainingPlan::create(['user_id' => $open->user_id, 'event_id' => $open->id, 'sessions' => [], 'is_active' => true]);
        $race = Event::create([
            'user_id' => $open->user_id, 'name' => 'Halbmarathon', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 30,
        ]);

        \Illuminate\Support\Facades\Queue::fake();
        $this->actingAs($open->user)
            ->postJson(route('events.plan.generate', $race))
            ->assertOk()
            ->assertJson(['generating' => true]);

        $this->assertSame('2026-11-16', $race->fresh()->plan_start_date->toDateString());
    }

    public function test_an_open_goal_can_be_created_on_the_race_page(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $this->actingAs($user)->post(route('events.store'), [
            'goal_type' => 'open', 'open_focus' => 'maintain', 'open_test' => false,
        ])->assertRedirect(route('events.index'));

        $event = $user->events()->first();
        $this->assertTrue($event->isOpen());
        $this->assertSame('Form halten', $event->name);
        $this->assertFalse($event->open_test);
        $this->assertSame('2027-01-31', $event->event_date->toDateString());
    }

    public function test_the_onboarding_saves_an_open_goal_and_back_to_a_race(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('onboarding.goal'), [
            'goal_type' => 'open', 'open_focus' => 'speed', 'race_distance' => '5km', 'open_test' => true,
        ])->assertOk();

        $event = $user->events()->sole();
        $this->assertTrue($event->isOpen());
        $this->assertSame('5km', $event->race_distance);

        // Zurück und doch ein Rennen: dasselbe Ziel wird ein Rennen.
        $this->actingAs($user)->postJson(route('onboarding.goal'), [
            'race_distance' => 'half_marathon', 'target_time_hours' => 1, 'target_time_minutes' => 40,
            'race_date' => '2027-04-11',
        ])->assertOk();

        $this->assertFalse($user->events()->sole()->isOpen());
    }

    public function test_a_later_start_in_the_interview_moves_the_first_block(): void
    {
        $event = $this->open();

        $this->actingAs($event->user)->post(route('plan-interview.store'), [
            'event_id' => $event->id, 'plan_start_date' => '2026-11-30',
        ])->assertRedirect();

        $this->assertSame('2026-11-30', $event->fresh()->plan_start_date->toDateString());
        $this->assertSame('2027-02-21', $event->fresh()->event_date->toDateString());
    }
}
