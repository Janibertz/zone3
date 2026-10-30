<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Run\SessionStructure;
use App\Services\WeeklyPatternService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Am festen Termin gibt der Laufclub jede Woche ein anderes Intervall vor
 * (Jan, 30.10.2026). Zone3 baut dort keinen Ablauf — weder beim Öffnen der
 * Details noch über den Bestand — und sagt dem Modell, dass es keinen
 * beschreiben soll.
 */
class FixedAppointmentStepsTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY   = '2026-11-30'; // Montag
    private const TUESDAY = '2026-12-01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY);
        CarbonImmutable::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function grid(): array
    {
        return [
            'monday'    => ['available' => true, 'duration_min' => 60],
            'tuesday'   => ['available' => true, 'duration_min' => 60, 'fixed' => ['type' => 'interval', 'label' => 'KRAFT']],
            'wednesday' => ['available' => true, 'duration_min' => 30],
            'thursday'  => ['available' => false, 'duration_min' => 0],
            'friday'    => ['available' => true, 'duration_min' => 120],
            'saturday'  => ['available' => true, 'duration_min' => 120],
            'sunday'    => ['available' => true, 'duration_min' => 180],
        ];
    }

    /** @return array{0: User, 1: TrainingSession, 2: Event} */
    private function athlete(string $date = self::TUESDAY, array $attributes = []): array
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->create(['threshold_speed' => 4.25, 'weekly_availability' => $this->grid()]);

        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 25,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        $session = TrainingSession::create($attributes + [
            'user_id' => $user->id, 'event_id' => $event->id, 'training_plan_id' => $plan->id,
            'planned_date' => $date, 'type' => 'interval', 'title' => 'Laufclub', 'intensity' => 'high',
            'distance_km' => 10.9, 'duration_min' => 60, 'status' => 'planned',
        ]);

        return [$user, $session, $event];
    }

    public function test_the_details_build_no_structure_for_the_club(): void
    {
        [$user, $session] = $this->athlete();

        $this->actingAs($user)->getJson(route('training-sessions.steps', $session->id))
            ->assertOk()
            ->assertJsonPath('steps', null)
            ->assertJsonPath('note', 'Den Ablauf gibt dein Termin vor.');

        $this->assertNull($session->fresh()->steps);
    }

    /** Was beim Öffnen früher gebaut wurde, verschwindet wieder. */
    public function test_an_old_structure_on_the_club_is_cleared(): void
    {
        [$user, $session] = $this->athlete(attributes: [
            'steps' => [['type' => 'work', 'label' => '6×1000 m', 'duration_min' => 30]],
            'workout_variant' => 'vo2_1000',
        ]);

        $this->actingAs($user)->getJson(route('training-sessions.steps', $session->id))->assertJsonPath('steps', null);

        $this->assertNull($session->fresh()->steps);
        $this->assertNull($session->fresh()->workout_variant);
    }

    /** Dasselbe Intervall an einem anderen Tag ist kein Termin und bekommt seinen Ablauf. */
    public function test_an_interval_on_another_day_still_gets_steps(): void
    {
        [$user, $session] = $this->athlete('2026-12-04');

        $this->actingAs($user)->getJson(route('training-sessions.steps', $session->id))->assertOk();

        $this->assertNotEmpty($session->fresh()->steps);
    }

    /** Verlangt der Athlet im Coach-Chat ausdrücklich einen Baustein, bekommt er ihn. */
    public function test_an_explicit_workout_still_builds(): void
    {
        [, $session] = $this->athlete();
        $structure = app(SessionStructure::class);

        $this->assertFalse($structure->apply($session));
        $this->assertTrue($structure->apply($session, 'vo2_1000'));
    }

    public function test_the_prompt_asks_for_no_structure_at_the_club(): void
    {
        [, , $event] = $this->athlete();
        $from = CarbonImmutable::parse(self::TODAY);

        $skeleton = app(WeeklyPatternService::class)->build(
            $event, $from, $from->addDays(6), $this->grid(), [], [], null, null,
            ['has_data' => true, 'next_week_max' => 45.0], 330,
        );

        $this->assertStringContainsString(
            'den Inhalt gibt der Termin vor',
            app(WeeklyPatternService::class)->toPromptSection($skeleton),
        );
    }
}
