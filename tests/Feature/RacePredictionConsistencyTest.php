<?php

namespace Tests\Feature;

use App\Jobs\GenerateRacePredictionJob;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\AI\CoachingTextService;
use App\Services\PredictFinishTimeService;
use App\Services\RacePredictionService;
use App\Services\Vdot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kachel und Text müssen dieselbe Zahl nennen.
 *
 * Die Prognose wurde an drei Stellen gerechnet: auf der Planseite, im
 * Dashboard und — nach einem ganz anderen Verfahren (Riegel) — im Job, der
 * den Text darunter schreibt. Angezeigt wurde die eine Zahl, geschrieben
 * die andere: 3:26 in der Kachel, 3:36 im Text. Beides am selben Tag,
 * beides für denselben Marathon.
 */
class RacePredictionConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RacePredictionService
    {
        return app(RacePredictionService::class);
    }

    // ── Die Formel ───────────────────────────────────────────────────────

    /**
     * Daniels' Tabelle („Daniels' Running Formula", 3. Aufl.) auf wenige
     * Sekunden. Die alten Faktoren lagen bei 5 km fast eine Minute daneben.
     *
     * @return array<string, array{float, string, int}>
     */
    public static function danielsTable(): array
    {
        return [
            'VDOT 40, 5 km'        => [40, '5km',           1448],  // 24:08
            'VDOT 40, Marathon'    => [40, 'marathon',     13785],  // 3:49:45
            'VDOT 50, 10 km'       => [50, '10km',          2481],  // 41:21
            'VDOT 50, Halbmarathon'=> [50, 'half_marathon', 5495],  // 1:31:35
            'VDOT 60, Marathon'    => [60, 'marathon',      9805],  // 2:43:25
            'VDOT 70, 5 km'        => [70, '5km',            897],  // 14:57
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('danielsTable')]
    public function test_the_prediction_matches_daniels_table(float $vdot, string $distance, int $tableSec): void
    {
        $result = $this->service()->forVdot($vdot, $distance);

        // 0,2 % — beim Marathon gut 20 s, beim 5er 2–3 s.
        $this->assertEqualsWithDelta($tableSec, $result['total_sec'], max(3, $tableSec * 0.002));
    }

    /** Schwellenpace 4:22 → VDOT 48,4 → Marathon 3:15:57 (vorher mit × 1,12: 3:26). */
    public function test_the_marathon_from_a_threshold(): void
    {
        $vdot   = Vdot::fromThresholdPace(262);
        $result = $this->service()->forVdot($vdot, 'marathon');

        $this->assertSame('3:15:57', $result['time']);
        $this->assertSame('4:39', $result['pace']);
    }

    public function test_all_four_distances_are_produced_for_the_dashboard(): void
    {
        $tiles = $this->service()->standardDistances(50);

        $this->assertSame(['5k', '10k', 'half', 'marathon'], array_keys($tiles));
        $this->assertSame('Halbmarathon', $tiles['half']['label']);
        $this->assertSame('19:56', $tiles['5k']['total_time']);
    }

    /** Planseite und Dashboard müssen dieselbe Marathonzeit nennen. */
    public function test_page_and_dashboard_agree(): void
    {
        $svc = $this->service();

        $this->assertSame(
            $svc->forVdot(47.3, 'marathon')['time'],
            $svc->standardDistances(47.3)['marathon']['total_time'],
        );
    }

    public function test_a_custom_distance_lies_between_its_neighbours(): void
    {
        $svc = $this->service();

        $fifteen = $svc->forVdot(45, 'custom', 15.0);
        $ten     = $svc->forVdot(45, '10km');
        $half    = $svc->forVdot(45, 'half_marathon');

        $this->assertGreaterThan($ten['total_sec'] / 10, $fifteen['total_sec'] / 15);
        $this->assertLessThan($half['total_sec'] / 21.0975, $fifteen['total_sec'] / 15);
    }

    public function test_without_a_vdot_there_is_no_prediction(): void
    {
        $this->assertNull($this->service()->forVdot(null, 'marathon'));
        $this->assertNull($this->service()->forVdot(45, 'custom', null));
    }

    // ── Der Text bekommt die angezeigte Zahl ─────────────────────────────

    public function test_the_text_is_written_from_the_displayed_number(): void
    {
        $user = User::factory()->onboarded()->create();
        RunnerProfile::create(['user_id' => $user->id, 'threshold_speed' => 4 + 22 / 60]);

        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Stadtmarathon',
            'event_date'          => now()->addDays(40),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        // Der Riegel-Predictor liefert bewusst etwas anderes.
        $this->mock(PredictFinishTimeService::class, function ($m) {
            $m->shouldReceive('predict')->andReturn([
                'predicted_finish_time'       => '3:36:00',
                'predicted_pace'              => '5:07',
                'prediction_trend'            => 'improving',
                'prediction_target_delta_sec' => -360,
                'prediction_run_count'        => 12,
            ]);
        });

        $seen = null;
        $this->mock(CoachingTextService::class, function ($m) use (&$seen) {
            $m->shouldReceive('withCoach')->andReturnSelf();
            $m->shouldReceive('forUser')->andReturnSelf();
            $m->shouldReceive('generateRacePredictionText')
                ->andReturnUsing(function ($data) use (&$seen) {
                    $seen = $data;
                    return 'Text über ' . $data['predicted_finish_time'];
                });
        });

        (new GenerateRacePredictionJob($plan->id))->handle(
            app(PredictFinishTimeService::class),
            app(CoachingTextService::class),
            app(RacePredictionService::class),
        );

        $this->assertStringStartsWith('3:15', $seen['predicted_finish_time'], 'Der Text bekommt die VDOT-Prognose');
        $this->assertSame('improving', $seen['prediction_trend'], 'Trend kommt weiter aus dem Predictor');
        $this->assertStringContainsString('3:15', $plan->refresh()->prediction_text);
        $this->assertStringStartsWith('3:15', $plan->predicted_finish_time);
    }

    /** Ohne Schwellenpace bleibt es bei der Riegel-Hochrechnung. */
    public function test_without_a_threshold_the_riegel_figure_is_kept(): void
    {
        $user = User::factory()->onboarded()->create();
        RunnerProfile::create(['user_id' => $user->id]);

        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Stadtmarathon',
            'event_date'          => now()->addDays(40),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        $this->mock(PredictFinishTimeService::class, function ($m) {
            $m->shouldReceive('predict')->andReturn([
                'predicted_finish_time'       => '3:36:00',
                'predicted_pace'              => '5:07',
                'prediction_trend'            => 'stable',
                'prediction_target_delta_sec' => -360,
                'prediction_run_count'        => 12,
            ]);
        });
        $this->mock(CoachingTextService::class, function ($m) {
            $m->shouldReceive('withCoach')->andReturnSelf();
            $m->shouldReceive('forUser')->andReturnSelf();
            $m->shouldReceive('generateRacePredictionText')->andReturn('Text');
        });

        (new GenerateRacePredictionJob($plan->id))->handle(
            app(PredictFinishTimeService::class),
            app(CoachingTextService::class),
            app(RacePredictionService::class),
        );

        $this->assertSame('3:36:00', $plan->refresh()->predicted_finish_time);
    }
}
