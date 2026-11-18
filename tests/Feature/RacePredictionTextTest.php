<?php

namespace Tests\Feature;

use App\Jobs\GenerateRacePredictionJob;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\RacePredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
