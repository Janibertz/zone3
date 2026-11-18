<?php

namespace Tests\Feature;

use App\Jobs\GenerateRacePredictionJob;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\RacePredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Prognose und KI-Text darunter müssen dieselbe Zeit meinen.
 *
 * 18.11.2026: oben 1:33:45, im Text 1:29:58. Der Text war am Vorabend
 * geschrieben worden, als noch die VO2max der Uhr den VDOT setzte; die
 * Planseite rechnet die Zahl bei jedem Aufruf neu, den Text nur alle 12 h.
 */
class RacePredictionTextTest extends TestCase
{
    use RefreshDatabase;

    private function setUpPlan(string $storedTime): array
    {
        $user = User::factory()->onboarded()->create();
        RunnerProfile::updateOrCreate(['user_id' => $user->id], ['threshold_speed' => 4.35]);

        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Halbmarathon Hannover',
            'event_date'          => now()->addDays(140),
            'race_distance'       => 'half_marathon',
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 25,
        ]);

        $plan = TrainingPlan::create([
            'user_id'               => $user->id,
            'event_id'              => $event->id,
            'is_active'             => true,
            'sessions'              => [],
            'predicted_finish_time' => $storedTime,
            'prediction_text'       => "{$storedTime} ist eine solide Basis.",
            'prediction_updated_at' => now()->subHour(),
        ]);

        return [$user, $event, $plan];
    }

    private function currentPrediction(User $user): string
    {
        $vdot = $user->runnerProfile()->first()->vdot();

        return app(RacePredictionService::class)->forVdot($vdot, 'half_marathon', null)['time'];
    }

    public function test_a_text_about_another_time_is_hidden_and_rewritten(): void
    {
        Queue::fake();
        [$user, $event] = $this->setUpPlan('1:29:58');

        $this->assertNotSame('1:29:58', $this->currentPrediction($user));

        $this->actingAs($user)
            ->get(route('events.plan.show', $event))
            ->assertInertia(fn ($page) => $page
                ->where('plan.predicted_finish_time', $this->currentPrediction($user))
                ->where('plan.prediction_text', null));

        Queue::assertPushed(GenerateRacePredictionJob::class);
    }

    public function test_a_matching_fresh_text_stays_and_costs_nothing(): void
    {
        Queue::fake();
        [$user, $event, $plan] = $this->setUpPlan('0:00:00');
        $time = $this->currentPrediction($user);
        $plan->update(['predicted_finish_time' => $time, 'prediction_text' => "{$time} ist eine solide Basis."]);

        $this->actingAs($user)
            ->get(route('events.plan.show', $event))
            ->assertInertia(fn ($page) => $page
                ->where('plan.prediction_text', "{$time} ist eine solide Basis."));

        Queue::assertNotPushed(GenerateRacePredictionJob::class);
    }

    /**
     * Mit VDOT kommen Grundlage und Trend aus der Schwelle, nicht aus der
     * Riegel-Hochrechnung („27 Läufe, leicht sinkend“ neben einer Zahl aus
     * der Schwelle).
     */
    public function test_the_prompt_names_the_vdot_and_the_threshold_trend(): void
    {
        [$user, , $plan] = $this->setUpPlan('0:00:00');
        $user->runnerProfile()->first()->update(['threshold_pace_history' => [
            ['date' => now()->subDays(30)->format('d.m.Y'), 'pace' => 4.25],
            ['date' => now()->subDays(2)->format('d.m.Y'),  'pace' => 4.35],
        ]]);
        config(['services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Neuer Text.'], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
        ], 200)]);

        GenerateRacePredictionJob::dispatchSync($plan->id);

        $time = $this->currentPrediction($user);
        Http::assertSent(function (Request $r) use ($time) {
            $body = json_encode($r->data(), JSON_UNESCAPED_UNICODE);
            return str_contains($body, "Prognostizierte Finishzeit: {$time}")
                && str_contains($body, 'Basiert auf: VDOT aus der Schwellenpace 4:21')
                && str_contains($body, 'Die Schwelle ist in den letzten Wochen langsamer geworden.')
                && ! str_contains($body, 'Läufen der letzten 90 Tage');
        });
        $plan->refresh();
        $this->assertSame($time, $plan->predicted_finish_time);
        $this->assertSame('Neuer Text.', $plan->prediction_text);
        $this->assertSame('declining', $plan->prediction_trend);
    }

    public function test_the_threshold_trend(): void
    {
        $d = fn (int $days) => now()->subDays($days)->format('d.m.Y');

        $this->assertNull(GenerateRacePredictionJob::thresholdTrend([]));
        $this->assertNull(GenerateRacePredictionJob::thresholdTrend([['date' => $d(3), 'pace' => 4.3]]));
        // Älter als sechs Wochen zählt nicht.
        $this->assertNull(GenerateRacePredictionJob::thresholdTrend([['date' => $d(60), 'pace' => 4.6], ['date' => $d(3), 'pace' => 4.3]]));
        $this->assertSame('improving', GenerateRacePredictionJob::thresholdTrend([['date' => $d(20), 'pace' => 4.40], ['date' => $d(3), 'pace' => 4.30]]));
        $this->assertSame('stable', GenerateRacePredictionJob::thresholdTrend([['date' => $d(20), 'pace' => 4.35], ['date' => $d(3), 'pace' => 4.34]]));
    }
}
