<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\SessionContentService;
use App\Services\Run\RunWorkouts;
use App\Services\TrainingPlanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Der Ablauf einer Laufeinheit kommt seit 29.10.2026 aus einem festen
 * Katalog statt aus einem KI-Aufruf (Entscheidung von Jan). Gemeldet war
 * „Tempolauf 10,7 km" in der Liste und 12 km in den Details — die Struktur
 * entstand erst beim Öffnen und passte nicht zur Einheit.
 */
class RunWorkoutsTest extends TestCase
{
    use RefreshDatabase;

    /** Paces eines Athleten mit Schwelle 4:20. */
    private const PACES = [
        'easy'          => '5:10–5:50',
        'long'          => '5:20–5:50',
        'marathon'      => '4:40',
        'threshold'     => '4:20',
        'sub_threshold' => '4:25–4:35',
        'interval'      => '3:58–4:05',
        'repetition'    => '3:45',
        'target_pace'   => '4:15',
    ];

    private function catalog(): RunWorkouts
    {
        return app(RunWorkouts::class);
    }

    // ── Der Katalog ──────────────────────────────────────────────────────

    /** Jede Variante ergibt einen Ablauf, dessen Abschnitte die Dauer ergeben. */
    public function test_every_variant_fills_exactly_the_planned_minutes(): void
    {
        foreach (RunWorkouts::VARIANTS as $type => $variants) {
            if ($type === 'long_run') {
                continue;
            }
            foreach ($variants as $variant) {
                $built = $this->catalog()->build($type, $variant, ['minutes' => 60, 'paces' => self::PACES]);

                $this->assertNotNull($built, $variant);
                // test_5k ist eine Strecke — die Dauer folgt der Pace.
                if ($variant !== 'test_5k') {
                    $this->assertEqualsWithDelta(60, $built['duration_min'], 1, $variant);
                }
                $this->assertSame($built['duration_min'], (int) round($this->catalog()->totalMinutes($built['steps'])), $variant);
                $this->assertSame(SessionContentService::stepsTotalKm($built['steps']), $built['distance_km'], $variant);
            }
        }
    }

    /**
     * Gemeldet nach dem Deploy: 30 min locker mit Steigerungen ergaben
     * 4,6 km, weil die 5 min Traben ohne Ein-/Auslaufen als 0 km zaehlten.
     */
    public function test_the_trot_between_strides_counts_towards_the_distance(): void
    {
        $built = $this->catalog()->build('easy_run', 'locker_steigerungen', ['minutes' => 30, 'paces' => self::PACES]);

        // 23,3 min bei 5:30 = 4,24 km, 5 × 20 s bei 3:45 = 0,44 km,
        // 5 × 1 min Traben bei 5:50 = 0,86 km.
        $this->assertSame(5.5, $built['distance_km']);
    }

    public function test_threshold_blocks_follow_the_hard_minutes(): void
    {
        $built = $this->catalog()->build('tempo_run', 'schwelle_bloecke', ['minutes' => 60, 'hard_min' => 25, 'paces' => self::PACES]);

        $work = collect($built['steps'])->firstWhere('type', 'work');
        $this->assertSame(12.0, (float) $work['duration_min']);
        $this->assertSame(2, $work['repetitions']);
        $this->assertSame('4:20', $built['pace_target']);
        $this->assertSame('Einlaufen', $built['steps'][0]['label']);
        $this->assertSame('Auslaufen', end($built['steps'])['label']);
    }

    /** Eine kurze Einheit bekommt einen kürzeren Hauptteil — sie wird nicht länger. */
    public function test_the_main_set_fits_a_short_session(): void
    {
        $built = $this->catalog()->build('tempo_run', 'schwelle_bloecke', ['minutes' => 35, 'hard_min' => 25, 'paces' => self::PACES]);

        $this->assertEqualsWithDelta(35, $built['duration_min'], 1);
    }

    /** Pausen bei Strecken-Intervallen sind Strecken (Entscheidung von Jan). */
    public function test_distance_intervals_rest_over_a_distance(): void
    {
        $built = $this->catalog()->build('interval', 'vo2_1000', ['minutes' => 60, 'hard_min' => 20, 'paces' => self::PACES]);

        $steps = collect($built['steps']);
        $this->assertSame(1000, $steps->firstWhere('type', 'work')['distance_m']);
        $this->assertSame(400, $steps->firstWhere('type', 'rest')['distance_m']);
        $this->assertNull($steps->firstWhere('type', 'rest')['pace_target']);
    }

    public function test_the_long_run_keeps_its_distance_and_ends_at_race_pace(): void
    {
        $built = $this->catalog()->build('long_run', 'lang_renntempo', ['km' => 18, 'race_km' => 4, 'paces' => self::PACES]);

        $this->assertSame(18.0, $built['distance_km']);
        $this->assertSame([14000, 4000], array_column($built['steps'], 'distance_m'));
        $this->assertSame('4:15', $built['steps'][1]['pace_target']);
        // Oben steht das Tempo des langen Blocks, nicht das der letzten km.
        $this->assertSame('5:20–5:50', $built['pace_target']);
    }

    public function test_an_unknown_choice_falls_back_to_an_allowed_variant(): void
    {
        $allowed = RunWorkouts::VARIANTS['interval'];

        $this->assertSame('vo2_800', RunWorkouts::pick($allowed, 'vo2_800'));
        $this->assertContains(RunWorkouts::pick($allowed, '8x400', '2026-11-02'), $allowed);
        $this->assertNull(RunWorkouts::pick([], 'locker'));
    }

    /** Der lange Lauf hat keine Wahl — die Leiter hat entschieden. */
    public function test_the_slot_decides_where_the_model_has_no_choice(): void
    {
        $this->assertSame(['lang_renntempo'], RunWorkouts::variantsFor(['type' => 'long_run', 'race_km' => 3]));
        $this->assertSame(['lang_locker'], RunWorkouts::variantsFor(['type' => 'long_run']));
        $this->assertSame(['locker'], RunWorkouts::variantsFor(['type' => 'easy_run', 'comeback' => 1]));
        $this->assertSame(['sub_6min'], RunWorkouts::variantsFor(['type' => 'tempo_run', 'controlled' => true, 'double' => 'am']));
        $this->assertSame(['fast_200'], RunWorkouts::variantsFor(['type' => 'interval', 'fast' => true]));
    }

    // ── Im Plan ──────────────────────────────────────────────────────────

    /** Der Validator baut den Ablauf aus dem Slot — die Zahlen des Modells zählen nicht. */
    public function test_the_validator_builds_the_structure_from_the_skeleton(): void
    {
        $date     = now()->addDays(2)->toDateString();
        $skeleton = [
            'days'  => [$date => ['date' => $date, 'slots' => [[
                'type' => 'tempo_run', 'target_min' => 60, 'max_min' => 90, 'hard_min' => 25, 'required' => true,
            ]]]],
            'paces' => self::PACES,
        ];

        $validator = new \ReflectionMethod(TrainingPlanValidator::class, 'buildStructures');
        $sessions  = $validator->invoke(app(TrainingPlanValidator::class), [[
            'date' => $date, 'type' => 'tempo_run', 'title' => 'Schwelle', 'description' => '3×10 min',
            'duration_min' => 60, 'distance_km' => 10.7, 'workout' => 'schwelle_bloecke',
        ]], $skeleton['days'], self::PACES);

        $s = $sessions[0];
        $this->assertSame('schwelle_bloecke', $s['workout']);
        $this->assertNotEmpty($s['steps']);
        $this->assertSame(SessionContentService::stepsTotalKm($s['steps']), $s['distance_km']);
        $this->assertSame('4:20', $s['pace_target']);
    }

    // ── Details und Bestand ──────────────────────────────────────────────

    private function athleteWithSession(array $attributes = []): array
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->create(['threshold_speed' => 4 + 20 / 60]);

        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Herbst-HM',
            'event_date' => now()->addWeeks(8), 'race_distance' => 'half_marathon',
            'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 30,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        $session = TrainingSession::create($attributes + [
            'user_id'          => $user->id,
            'event_id'         => $event->id,
            'training_plan_id' => $plan->id,
            'planned_date'     => now()->addDay()->toDateString(),
            'type'             => 'tempo_run',
            'title'            => 'Schwelle',
            'intensity'        => 'high',
            'distance_km'      => 10.7,
            'duration_min'     => 60,
            'status'           => 'planned',
        ]);

        return [$user, $session];
    }

    /** Eine ältere Einheit ohne Schritte bekommt sie aus dem Katalog — ohne einen Aufruf nach außen. */
    public function test_opening_an_old_session_builds_steps_without_any_ai_call(): void
    {
        [$user, $session] = $this->athleteWithSession();

        // Http::preventStrayRequests() im TestCase: ein Modellaufruf würde werfen.
        $this->actingAs($user)
            ->getJson(route('training-sessions.steps', $session->id))
            ->assertOk()
            ->assertJsonPath('steps.0.label', 'Einlaufen');

        $session->refresh();
        $this->assertNotEmpty($session->steps);
        $this->assertNotNull($session->workout_variant);
        $this->assertSame(SessionContentService::stepsTotalKm($session->steps), $session->distance_km);
        $this->assertSame(0, \App\Models\AiLog::count());
    }

    public function test_the_rebuild_command_leaves_pinned_sessions_alone(): void
    {
        $old = [['type' => 'work', 'label' => 'Alt', 'duration_min' => 60, 'pace_target' => '4:20', 'repetitions' => null]];
        [$user, $session] = $this->athleteWithSession(['steps' => $old]);
        $pinned = $session->replicate()->fill([
            'planned_date' => now()->addDays(3)->toDateString(), 'pinned_at' => now(), 'steps' => $old,
        ]);
        $pinned->save();

        Artisan::call('sessions:rebuild-steps');

        $this->assertNotEquals($old, $session->fresh()->steps);
        $this->assertSame('Einlaufen', $session->fresh()->steps[0]['label']);
        $this->assertEquals($old, $pinned->fresh()->steps);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        [$user, $session] = $this->athleteWithSession();

        Artisan::call('sessions:rebuild-steps', ['--dry-run' => true]);

        $this->assertNull($session->fresh()->steps);
        $this->assertSame(10.7, (float) $session->fresh()->distance_km);
    }
}
