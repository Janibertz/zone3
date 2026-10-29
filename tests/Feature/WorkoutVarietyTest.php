<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AI\SessionContentService;
use App\Services\Garmin\GarminWorkoutBuilder;
use App\Services\Run\RunWorkouts;
use App\Services\TrainingPlanValidator;
use App\Services\WeeklyPatternService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gemeldet am 29.10.2026: „Die Intervalle sind mir viel zu wenig … So
 * wiederholen sich zu schnell die Einheiten." Zwölf Bausteine wurden 32,
 * dazu eine Rotation über drei Wochen und eine Auswahl je Phase.
 */
class WorkoutVarietyTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_every_variant_has_a_prompt_text_and_a_purpose(): void
    {
        foreach (RunWorkouts::VARIANTS as $variants) {
            foreach ($variants as $v) {
                $this->assertArrayHasKey($v, RunWorkouts::DESCRIPTIONS, $v);
                $this->assertArrayHasKey($v, RunWorkouts::PURPOSE, $v);
            }
        }

        $this->assertSame(32, count(array_merge(...array_values(RunWorkouts::VARIANTS))));
    }

    /** Ohne Paces (kein VDOT) entsteht trotzdem ein Ablauf. */
    public function test_every_variant_builds_without_paces(): void
    {
        foreach (RunWorkouts::VARIANTS as $type => $variants) {
            foreach ($variants as $v) {
                $built = $this->catalog()->build($type, $v, ['minutes' => 60, 'km' => 16, 'race_km' => 4]);
                $this->assertNotNull($built, $v);
                $this->assertNotEmpty($built['steps'], $v);
            }
        }
    }

    /** Am Hang sagt die Pace nichts — die Belastung trägt nur eine Zone. */
    public function test_hill_sessions_carry_no_pace_on_the_hill(): void
    {
        foreach (['easy_run' => 'locker_bergsprints', 'interval' => 'bergintervalle'] as $type => $v) {
            $built = $this->catalog()->build($type, $v, ['minutes' => 50, 'paces' => self::PACES]);
            $hill  = collect($built['steps'])->first(fn ($s) => $s['zone'] === 5);

            $this->assertNull($hill['pace_target'], $v);
        }

        // Und die Uhr bekommt die Einheit trotzdem, nur ohne Pace-Ziel.
        $built   = $this->catalog()->build('interval', 'bergintervalle', ['minutes' => 50, 'paces' => self::PACES]);
        $payload = app(GarminWorkoutBuilder::class)->build('Berg', null, $built['steps']);
        $this->assertNotEmpty($payload);
    }

    public function test_the_long_run_variants_keep_the_ladder_distance(): void
    {
        foreach (['lang_renntempo_bloecke', 'lang_progressiv', 'lang_steigerungen'] as $v) {
            $built = $this->catalog()->build('long_run', $v, ['km' => 18, 'race_km' => 6, 'paces' => self::PACES]);
            $this->assertEqualsWithDelta(18, $built['distance_km'], 0.11, $v);
        }

        $blocks = $this->catalog()->build('long_run', 'lang_renntempo_bloecke', ['km' => 18, 'race_km' => 6, 'paces' => self::PACES]);
        $race   = collect($blocks['steps'])->firstWhere('label', 'Zielrenntempo');
        $this->assertSame(3, $race['repetitions']);
        $this->assertSame(2000, $race['distance_m']);
        $this->assertSame('4:15', $race['pace_target']);
    }

    public function test_the_threshold_pyramid_fits_the_hard_minutes(): void
    {
        $built = $this->catalog()->build('tempo_run', 'schwelle_pyramide', ['minutes' => 70, 'hard_min' => 28, 'paces' => self::PACES]);

        $this->assertSame('4-6-8-6-4 min Schwelle', $built['summary']);
    }

    // ── Phase und Hang ───────────────────────────────────────────────────

    public function test_the_phase_decides_which_intervals_are_offered(): void
    {
        $this->assertSame(['fahrtspiel_pyramide', 'fahrtspiel_1_1', 'bergintervalle'], RunWorkouts::variantsFor(['type' => 'interval', 'phase' => 'base']));
        $this->assertSame(['vo2_400', 'vo2_30_30'], RunWorkouts::variantsFor(['type' => 'interval', 'phase' => 'taper']));
        $this->assertContains('renntempo_bloecke', RunWorkouts::variantsFor(['type' => 'tempo_run', 'phase' => 'peak']));
        $this->assertNotContains('renntempo_bloecke', RunWorkouts::variantsFor(['type' => 'tempo_run', 'phase' => 'base']));
    }

    public function test_without_hills_there_are_no_hill_sessions(): void
    {
        $this->assertSame(['fahrtspiel_pyramide', 'fahrtspiel_1_1'], RunWorkouts::variantsFor(['type' => 'interval', 'phase' => 'base', 'hills' => false]));
        $this->assertNotContains('locker_bergsprints', RunWorkouts::variantsFor(['type' => 'easy_run', 'hills' => false]));
    }

    public function test_the_profile_saves_the_hill_switch(): void
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->create(['threshold_speed' => 4.5]);

        $this->actingAs($user)->postJson(route('onboarding.training-model'), [
            'training_model' => 'polarized', 'has_lactate_meter' => false, 'hills_available' => false,
        ])->assertOk();

        $this->assertFalse($user->runnerProfile->fresh()->hills_available);
    }

    // ── Rotation ─────────────────────────────────────────────────────────

    public function test_a_variant_from_the_last_three_weeks_is_left_out(): void
    {
        $allowed = ['vo2_1000', 'vo2_800', 'vo2_400'];
        $history = ['vo2_1000' => '2026-11-02', 'vo2_800' => '2026-10-01'];

        $this->assertSame(['vo2_800', 'vo2_400'], RunWorkouts::fresh($allowed, $history, '2026-11-16'));
    }

    public function test_the_plain_run_never_drops_out(): void
    {
        $fresh = RunWorkouts::fresh(['locker', 'locker_steigerungen'], ['locker' => '2026-11-15', 'locker_steigerungen' => '2026-11-14'], '2026-11-16');

        $this->assertSame(['locker'], $fresh);
        $this->assertSame('locker', RunWorkouts::pick(['locker', 'locker_bergsprints'], null, '2026-11-16'));
    }

    public function test_when_everything_ran_recently_the_oldest_comes_back(): void
    {
        $history = ['vo2_400' => '2026-11-10', 'vo2_30_30' => '2026-11-03'];

        $this->assertSame(['vo2_30_30'], RunWorkouts::fresh(['vo2_400', 'vo2_30_30'], $history, '2026-11-16'));
    }

    public function test_without_a_choice_the_least_recently_used_wins(): void
    {
        $history = ['vo2_1000' => '2026-10-20', 'vo2_800' => '2026-09-01'];

        $this->assertSame('vo2_800', RunWorkouts::pick(['vo2_1000', 'vo2_800'], null, '2026-11-16', $history));
    }

    /** Zwei Wochen im selben Plan: die zweite bekommt nicht dasselbe Intervall. */
    public function test_the_validator_rotates_within_the_window(): void
    {
        $days = [];
        $sessions = [];
        foreach (['2026-11-17', '2026-11-24'] as $date) {
            $days[$date] = ['date' => $date, 'slots' => [[
                'type' => 'interval', 'target_min' => 60, 'max_min' => 90, 'hard_min' => 20, 'required' => true, 'phase' => 'build',
            ]]];
            $sessions[] = ['date' => $date, 'type' => 'interval', 'title' => 'Intervalle', 'duration_min' => 60, 'workout' => 'vo2_1000'];
        }

        $build  = new \ReflectionMethod(TrainingPlanValidator::class, 'buildStructures');
        $result = $build->invoke(app(TrainingPlanValidator::class), $sessions, $days, self::PACES, [], true);

        $this->assertSame('vo2_1000', $result[0]['workout']);
        $this->assertNotSame('vo2_1000', $result[1]['workout']);
    }

    public function test_the_history_before_the_window_blocks_the_choice(): void
    {
        $date = '2026-11-17';
        $days = [$date => ['date' => $date, 'slots' => [[
            'type' => 'tempo_run', 'target_min' => 60, 'max_min' => 90, 'hard_min' => 25, 'required' => true, 'phase' => 'build',
        ]]]];

        $build  = new \ReflectionMethod(TrainingPlanValidator::class, 'buildStructures');
        $result = $build->invoke(app(TrainingPlanValidator::class), [
            ['date' => $date, 'type' => 'tempo_run', 'title' => 'Schwelle', 'duration_min' => 60, 'workout' => 'schwelle_bloecke'],
        ], $days, self::PACES, ['schwelle_bloecke' => '2026-11-10'], true);

        $this->assertNotSame('schwelle_bloecke', $result[0]['workout']);
        $this->assertSame(SessionContentService::stepsTotalKm($result[0]['steps']), $result[0]['distance_km']);
    }

    /** Der Prompt bietet nur an, was die Rotation übrig lässt. */
    public function test_the_prompt_offers_only_fresh_variants(): void
    {
        $choices = new \ReflectionMethod(WeeklyPatternService::class, 'workoutChoices');
        $text    = $choices->invoke(app(WeeklyPatternService::class),
            ['type' => 'interval', 'phase' => 'base'],
            '2026-11-17',
            ['hills' => false, 'workout_history' => ['fahrtspiel_pyramide' => '2026-11-10']],
        );

        $this->assertStringContainsString('fahrtspiel_1_1', $text);
        $this->assertStringNotContainsString('fahrtspiel_pyramide', $text);
        $this->assertStringNotContainsString('bergintervalle', $text);
    }
}
