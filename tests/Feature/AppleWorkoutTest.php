<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Watch\AppleWorkoutWriter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Laufeinheiten als .workout-Datei für die Apple Watch.
 *
 * Die beiden Goldwerte sind Dateien, die Jan am 25.10.2026 auf dem iPhone
 * importiert hat — Vorschau „1.000m" und „Pace, 4'10''–4'20'' KM". Das
 * Format ist von Apple nicht dokumentiert; ändert sich hier ein Byte, muss
 * es wieder am Gerät geprüft werden, nicht nur hier.
 */
class AppleWorkoutTest extends TestCase
{
    use RefreshDatabase;

    /** 10 min · 5 × (1000 m / 2 min) · 10 min — importiert. */
    private const METER = '4a2446413341433443342d383838452d343236412d384146462d4545393541464530384146355a6e082510031a105a6f6e65332054657374204d6574657222110a0f0801120b0801110000000000c082402a300a15080112110a0f0803220b0801110000000000408f400a15080212110a0f0801120b0801110000000000005e40100532110a0f0801120b0801110000000000c08240c03e01d03e05';

    /** Dasselbe mit Pace-Alarm 4:10–4:20 auf der Belastung — importiert. */
    private const PACE_RANGE = '4a2446324643323734302d364337362d343138452d393435392d4530304531344143363341395aae01082510031a0e506163652042657265696368204122110a0f0801120b0801110000000000c082402a720a57080112530a0f0803220b0801110000000000408f40124008021002223a12380a1a0a0b0801114fecc44eecc40e40120b080111000000000000f03f121a0a0b0801110000000000001040120b080111000000000000f03f0a15080212110a0f0801120b0801110000000000005e40100532110a0f0801120b0801110000000000c08240c03e01d03e05';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function intervals(?string $pace): array
    {
        return [
            ['type' => 'warmup',   'label' => 'Einlaufen', 'duration_min' => 10, 'pace_target' => '6:00', 'zone' => 1, 'repetitions' => null],
            ['type' => 'work',     'label' => '1 km',      'distance_m' => 1000, 'duration_min' => 4, 'pace_target' => $pace, 'zone' => 5, 'repetitions' => 5],
            ['type' => 'rest',     'label' => 'Trabpause', 'duration_min' => 2, 'pace_target' => null, 'zone' => 1, 'repetitions' => 5],
            ['type' => 'cooldown', 'label' => 'Auslaufen', 'duration_min' => 10, 'pace_target' => '6:30', 'zone' => 1, 'repetitions' => null],
        ];
    }

    private function golden(string $hex, string $name, ?string $pace): void
    {
        $bytes  = hex2bin($hex);
        $planId = substr($bytes, 2, 36);

        $this->assertSame($hex, bin2hex(app(AppleWorkoutWriter::class)->write($name, $this->intervals($pace), $planId)));
    }

    public function test_distance_steps_match_the_file_the_watch_accepted(): void
    {
        $this->golden(self::METER, 'Zone3 Test Meter', null);
    }

    public function test_a_pace_range_matches_the_file_the_watch_accepted(): void
    {
        $this->golden(self::PACE_RANGE, 'Pace Bereich A', '4:10-4:20');
    }

    public function test_a_single_pace_becomes_the_same_range_garmin_gets(): void
    {
        [$slow, $fast] = AppleWorkoutWriter::paceRange('4:00');

        $this->assertEqualsWithDelta(4.1667 * 0.95, $slow, 0.001);
        $this->assertEqualsWithDelta(4.1667 * 1.05, $fast, 0.001);
    }

    public function test_pace_ranges_are_read_in_any_order_and_with_a_unit(): void
    {
        $this->assertEquals(AppleWorkoutWriter::paceRange('4:10-4:20'), AppleWorkoutWriter::paceRange('4:20–4:10 min/km'));
        $this->assertNull(AppleWorkoutWriter::paceRange('locker'));
        $this->assertNull(AppleWorkoutWriter::paceRange(null));
    }

    public function test_warmup_and_cooldown_carry_no_pace_alarm(): void
    {
        $file = app(AppleWorkoutWriter::class)->write('x', $this->intervals('4:10'), str_repeat('A', 36));

        // Ein Alarm pro Datei: der auf der Belastung, nicht auf 6:00 und 6:30.
        $this->assertSame(1, substr_count($file, "\x08\x02\x10\x02\x22"));
    }

    private function plannedRun(User $user, array $extra = []): TrainingSession
    {
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-03-14',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        return TrainingSession::create($extra + [
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => '2026-10-27', 'type' => 'interval', 'title' => '5×1000 m',
            'description' => 'Hart', 'intensity' => 'high', 'status' => 'planned',
            'steps' => $this->intervals('4:10-4:20'),
        ]);
    }

    public function test_a_run_downloads_as_workout(): void
    {
        $user = User::factory()->onboarded()->create();

        $response = $this->actingAs($user)->get(route('training-sessions.apple-workout', $this->plannedRun($user)));

        $response->assertOk();
        $this->assertStringContainsString('attachment; filename="zone3-2026-10-27-5x1000-m.workout"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('5×1000 m', $response->getContent());
    }

    public function test_without_steps_the_session_is_one_section(): void
    {
        $user    = User::factory()->onboarded()->create();
        $session = $this->plannedRun($user, ['type' => 'easy_run', 'title' => 'Locker', 'steps' => null, 'distance_km' => 8, 'duration_min' => 48, 'pace_target' => '5:50-6:10']);

        $steps = app(AppleWorkoutWriter::class)->stepsFor($session);

        $this->assertCount(1, $steps);
        $this->assertSame(8000, $steps[0]['distance_m']);
        $this->actingAs($user)->get(route('training-sessions.apple-workout', $session))->assertOk();
    }

    public function test_rides_rest_days_and_races_have_no_workout(): void
    {
        $user = User::factory()->onboarded()->create();

        foreach ([
            ['type' => 'cross_training', 'sport_type' => 'VirtualRide'],
            ['type' => 'rest'],
            ['type' => 'race_prep'],
            ['type' => 'strength'],
        ] as $extra) {
            $this->actingAs($user)->get(route('training-sessions.apple-workout', $this->plannedRun($user, $extra)))->assertNotFound();
        }
    }

    public function test_someone_elses_session_is_refused(): void
    {
        $session = $this->plannedRun(User::factory()->onboarded()->create());

        $this->actingAs(User::factory()->onboarded()->create())
            ->get(route('training-sessions.apple-workout', $session))
            ->assertForbidden();
    }
}
