<?php

namespace Tests\Feature;

use App\Jobs\GenerateEventTrainingPlanJob;
use App\Jobs\RegeneratePlanJob;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\TrainingPlanGenerator;
use App\Services\PlanContextBuilder;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Plan konnte nicht gespeichert werden" — gemeldet am 30.10.2026.
 *
 * Seit dem Katalog fragt der Prompt kein `pace_target` mehr ab; die Laufpace
 * setzt der Validator aus den Abschnitten. Ruhetage und Mobility bekamen sie
 * nie, und beide Plan-Jobs lasen `$s['pace_target']` ohne Vorgabe. Unter
 * Laravel ist ein fehlender Schlüssel eine Exception — jeder neue Plan brach
 * beim Speichern ab, nachdem der Modellaufruf schon bezahlt war.
 *
 * Die Antwort hier hat genau die Form, die der Prompt verlangt.
 */
class PlanJobSavesCatalogAnswerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-30 08:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30 08:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function athlete(): array
    {
        $user = User::factory()->create();
        RunnerProfile::create(['user_id' => $user->id, 'threshold_speed' => 4.25, 'weekly_availability' => [
            'monday'    => ['available' => true,  'duration_min' => 60],
            'tuesday'   => ['available' => true,  'duration_min' => 60, 'fixed' => ['type' => 'easy_run', 'label' => 'Laufclub']],
            'wednesday' => ['available' => true,  'duration_min' => 30],
            'thursday'  => ['available' => false, 'duration_min' => 0],
            'friday'    => ['available' => true,  'duration_min' => 120],
            'saturday'  => ['available' => true,  'duration_min' => 120],
            'sunday'    => ['available' => true,  'duration_min' => 180],
        ]]);
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Halbmarathon', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 25,
        ]);

        return [$user, $event];
    }

    /** Die Modellantwort im Format des Prompts: kein pace_target, kein zone. */
    private function fakeModel(User $user, Event $event): void
    {
        $context = app(PlanContextBuilder::class)->build($user, $event, []);
        $answer  = [];
        foreach ($context->skeleton['days'] as $date => $day) {
            foreach ($day['slots'] as $slot) {
                $answer[] = [
                    'date' => $date, 'type' => $slot['type'], 'title' => 'Einheit',
                    'description' => 'Locker.', 'distance_km' => 0, 'duration_min' => 30,
                    'intensity' => 'low', 'part' => null,
                ];
            }
        }

        $planner = \Mockery::mock(TrainingPlanGenerator::class)->makePartial();
        $planner->shouldReceive('withCoach')->andReturnSelf();
        $planner->shouldReceive('forUser')->andReturnSelf();
        $planner->shouldReceive('generateEventTrainingPlan')->andReturn($answer);
        $this->app->instance(TrainingPlanGenerator::class, $planner);
    }

    public function test_a_new_plan_is_saved(): void
    {
        [$user, $event] = $this->athlete();
        $this->fakeModel($user, $event);

        dispatch_sync(new GenerateEventTrainingPlanJob($event->id, $user->id));

        $this->assertNull($event->fresh()->plan_error);
        $this->assertTrue(TrainingSession::where('user_id', $user->id)->where('type', 'rest')->exists());
        $this->assertTrue(TrainingSession::where('user_id', $user->id)->where('type', 'easy_run')->whereNotNull('steps')->exists());
    }

    public function test_a_regeneration_is_saved(): void
    {
        [$user, $event] = $this->athlete();
        $this->fakeModel($user, $event);
        dispatch_sync(new GenerateEventTrainingPlanJob($event->id, $user->id));
        $before = TrainingPlan::where('user_id', $user->id)->value('id');

        // Ein leerer Plan zwingt die Neuberechnung, das Modell zu fragen.
        TrainingSession::where('user_id', $user->id)->delete();
        TrainingPlan::whereKey($before)->update(['needs_plan_update' => true]);
        dispatch_sync(new RegeneratePlanJob($user->id, RegeneratePlanJob::REASON_MANUAL));

        $this->assertNotNull($before);
        $this->assertTrue(TrainingSession::where('user_id', $user->id)->where('type', 'rest')->exists());
    }
}
