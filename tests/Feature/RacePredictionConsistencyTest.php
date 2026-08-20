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

    /** Marathon = Schwellenpace × 1,12. Bei 4:22/km sind das 3:26:xx. */
    public function test_the_marathon_multiplier(): void
    {
        // 4:22 min/km als Dezimalminuten
        $result = $this->service()->fromThreshold(4 + 22 / 60, 'marathon');

        $this->assertSame('4:53', $result['pace']);
        $this->assertStringStartsWith('3:26', $result['time']);
    }

    public function test_all_four_anchors_are_produced_for_the_dashboard(): void
    {
        $tiles = $this->service()->standardDistances(5.0);

        $this->assertSame(['5k', '10k', 'half', 'marathon'], array_keys($tiles));
        $this->assertSame('Halbmarathon', $tiles['half']['label']);
        $this->assertSame('4:30', $tiles['5k']['pace']);      // 5:00 × 0,90
    }

    /** Planseite und Dashboard müssen dieselbe Marathonzeit nennen. */
    public function test_page_and_dashboard_agree(): void
    {
        $svc = $this->service();

        $this->assertSame(
            $svc->fromThreshold(4.75, 'marathon')['time'],
            $svc->standardDistances(4.75)['marathon']['total_time'],
        );
    }

    public function test_a_custom_distance_is_interpolated(): void
    {
        $svc = $this->service();

        $fifteen = $svc->fromThreshold(5.0, 'custom', 15.0);
        $ten     = $svc->fromThreshold(5.0, '10km');
        $half    = $svc->fromThreshold(5.0, 'half_marathon');

        // 15 km liegt zwischen den Ankern — die Pace also auch.
        $this->assertGreaterThan($ten['total_sec'] / 10, $fifteen['total_sec'] / 15);
        $this->assertLessThan($half['total_sec'] / 21.0975, $fifteen['total_sec'] / 15);
    }

    public function test_without_a_threshold_there_is_no_prediction(): void
    {
        $this->assertNull($this->service()->fromThreshold(0.0, 'marathon'));
        $this->assertNull($this->service()->fromThreshold(5.0, 'custom', null));
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

        $this->assertStringStartsWith('3:26', $seen['predicted_finish_time'], 'Der Text bekommt die Schwellen-Prognose');
        $this->assertSame('improving', $seen['prediction_trend'], 'Trend kommt weiter aus dem Predictor');
        $this->assertStringContainsString('3:26', $plan->refresh()->prediction_text);
        $this->assertStringStartsWith('3:26', $plan->predicted_finish_time);
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
