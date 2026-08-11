<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Services\AI\TrainingPlanGenerator;
use App\Services\PlanContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Der Weg vom Kontext bis zum fertigen Plan, einmal komplett durchlaufen.
 *
 * Diese Strecke war bis hierher voellig ungetestet: Fehler darin zeigten
 * sich erst als "Der Coach konnte gerade keinen Plan erstellen" — die
 * Meldung, mit der der Job jede Exception abfaengt.
 */
class PlanGenerationSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function athlete(): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $user->runnerProfile()->create([
            'threshold_speed'      => 5.5,
            'threshold_heart_rate' => 165,
            'max_heart_rate'       => 190,
            'weekly_availability'  => collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
                ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => 90]])
                ->all(),
        ]);

        return $user->refresh();
    }

    private function event(User $user, string $distance = 'half_marathon'): Event
    {
        return Event::create([
            'user_id'             => $user->id,
            'name'                => 'Testrennen',
            'event_date'          => now()->addDays(60),
            'race_distance'       => $distance,
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 45,
        ]);
    }

    private function fakeReply(array $sessions): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message'       => ['content' => json_encode($sessions)],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ]),
        ]);
    }

    public function test_a_plan_can_be_generated_end_to_end(): void
    {
        $user  = $this->athlete();
        $event = $this->event($user);

        $this->fakeReply([[
            'date'         => now()->toDateString(),
            'type'         => 'easy_run',
            'title'        => 'Lockerer Lauf',
            'description'  => 'Ruhig in Zone 2.',
            'distance_km'  => 8,
            'duration_min' => 45,
            'pace_target'  => '6:00-6:30',
            'zone'         => 2,
            'intensity'    => 'low',
        ]]);

        $context = app(PlanContextBuilder::class)->build($user, $event);
        $result  = app(TrainingPlanGenerator::class)
            ->withCoach(null)
            ->forUser($user->id)
            ->generateEventTrainingPlan($context);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Der Coach-Chat ruft OpenAI mit Werkzeugen auf und ging deshalb einen
     * eigenen Weg am Transport vorbei — beim Zerlegen fiel ihm dabei der
     * Zugangsschluessel weg, ohne dass ein Test es gemerkt haette.
     */
    public function test_the_coach_chat_reaches_the_api(): void
    {
        $user = $this->athlete();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message'       => ['content' => 'Alles klar, viel Erfolg!'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20, 'total_tokens' => 100],
            ]),
        ]);

        $result = app(\App\Services\AI\CoachChatService::class)
            ->forUser($user->id)
            ->chatWithCoachTools($user, [], 'Wie läuft es?');

        $this->assertSame('Alles klar, viel Erfolg!', $result['reply']);
    }

    /** Der Backyard-Zweig baut einen voellig anderen Prompt. */
    public function test_the_backyard_branch_also_works(): void
    {
        $user  = $this->athlete();
        $event = $this->event($user, 'backyard_ultra');
        $event->update(['target_yards' => 24]);

        $this->fakeReply([[
            'date'         => now()->toDateString(),
            'type'         => 'time_on_feet',
            'title'        => 'Lockere Stunde',
            'description'  => 'Sehr ruhig.',
            'distance_km'  => 9,
            'duration_min' => 60,
            'pace_target'  => '7:00',
            'zone'         => 2,
            'intensity'    => 'low',
        ]]);

        $context = app(PlanContextBuilder::class)->build($user, $event->refresh());
        $result  = app(TrainingPlanGenerator::class)
            ->forUser($user->id)
            ->generateEventTrainingPlan($context);

        $this->assertIsArray($result);
    }
}
