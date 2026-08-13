<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Die KI-Endpunkte einmal ueber die Route aufgerufen.
 *
 * Die Smoke-Tests fuer die Dienste haben den Bruch nach dem Zerlegen nicht
 * gefunden: kaputt war die Verdrahtung im Controller, nicht der Dienst.
 * Genau diese Schicht deckt dieser Test ab — er faehrt durch Route,
 * Container und Controller bis zur HTTP-Anfrage.
 */
class AiEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function athlete(): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $user->runnerProfile()->create([
            'threshold_speed'      => 5.5,
            'threshold_heart_rate' => 165,
            'max_heart_rate'       => 190,
        ]);

        return $user->refresh();
    }

    private function fakeReply(string $content): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message'       => ['content' => $content],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'total_tokens' => 70],
            ]),
        ]);
    }

    public function test_the_coach_chat_endpoint_answers(): void
    {
        $this->fakeReply('Klingt gut, bleib dran!');

        $this->actingAs($this->athlete())
            ->postJson(route('coach.send'), ['message' => 'Wie läuft mein Training?'])
            ->assertOk()
            ->assertJsonPath('response', 'Klingt gut, bleib dran!');
    }

    /**
     * Der Coach antwortet oft nicht mit Text, sondern ruft erst ein Werkzeug
     * auf. Genau dieser Weg war bisher ungetestet — der andere Test faelscht
     * eine reine Textantwort und laeuft an der Schleife vorbei.
     */
    public function test_the_coach_can_call_a_tool_and_then_answer(): void
    {
        $user = $this->athlete();

        $toolCall = [
            'choices' => [[
                'message' => [
                    'role'       => 'assistant',
                    'content'    => null,
                    'tool_calls' => [[
                        'id'       => 'call_1',
                        'type'     => 'function',
                        'function' => [
                            'name'      => 'remember_user_fact',
                            'arguments' => json_encode(['fact' => 'Trainiert am liebsten morgens']),
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 4800, 'completion_tokens' => 570, 'total_tokens' => 5370],
        ];

        $answer = [
            'choices' => [[
                'message'       => ['role' => 'assistant', 'content' => 'Notiert!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5000, 'completion_tokens' => 20, 'total_tokens' => 5020],
        ];

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push($toolCall)
                ->push($answer),
        ]);

        $this->actingAs($user)
            ->postJson(route('coach.send'), ['message' => 'Merk dir das bitte.'])
            ->assertOk()
            ->assertJsonPath('response', 'Notiert!');

        $this->assertStringContainsString(
            'Trainiert am liebsten morgens',
            $user->refresh()->runnerProfile->coach_notes,
        );
    }

    /**
     * Jedes Werkzeug einmal ausloesen. Der Coach entscheidet selbst, welches
     * er nimmt — faellt eines davon um, sieht der Athlet nur "Server Error".
     */
    public static function toolProvider(): array
    {
        return [
            'merken'          => ['remember_user_fact',      ['fact' => 'Mag Intervalle']],
            'einheit ändern'  => ['modify_today_session',    ['type' => 'interval', 'duration_min' => 45]],
            'einheiten absagen' => ['skip_training_sessions', ['date_from' => '2026-08-13', 'date_to' => '2026-08-14', 'reason' => 'krank']],
            'zielzeit ändern' => ['update_event_target',     ['event_id' => 1, 'target_hours' => 3, 'target_minutes' => 15]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('toolProvider')]
    public function test_every_coach_tool_survives_being_called(string $tool, array $args): void
    {
        $user = $this->athlete();

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role'       => 'assistant',
                            'content'    => null,
                            'tool_calls' => [[
                                'id'       => 'call_1',
                                'type'     => 'function',
                                'function' => ['name' => $tool, 'arguments' => json_encode($args)],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                    'usage' => ['prompt_tokens' => 4800, 'completion_tokens' => 570, 'total_tokens' => 5370],
                ])
                ->push([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Erledigt.'], 'finish_reason' => 'stop']],
                    'usage'   => ['prompt_tokens' => 5000, 'completion_tokens' => 10, 'total_tokens' => 5010],
                ]),
        ]);

        $this->actingAs($user)
            ->postJson(route('coach.send'), ['message' => 'Mach das bitte.'])
            ->assertOk()
            ->assertJsonPath('response', 'Erledigt.');
    }

    /** Die Antwort des Coaches wird gespeichert, sonst ist der Verlauf luecken haft. */
    public function test_the_coach_reply_is_stored(): void
    {
        $this->fakeReply('Alles im grünen Bereich.');
        $user = $this->athlete();

        $this->actingAs($user)->postJson(route('coach.send'), ['message' => 'Hallo'])->assertOk();

        $this->assertDatabaseHas('coach_messages', [
            'user_id' => $user->id,
            'role'    => 'assistant',
            'content' => 'Alles im grünen Bereich.',
        ]);
    }

    public function test_the_nutrition_endpoint_answers(): void
    {
        $this->fakeReply(json_encode([
            'before' => [['icon' => '🍝', 'text' => 'Zwei Stunden vorher Haferflocken.']],
            'during' => [['icon' => '💧', 'text' => 'Alle 20 Minuten trinken.']],
            'after'  => [['icon' => '🥩', 'text' => 'Innerhalb 30 Minuten Protein.']],
        ]));

        $user = $this->athlete();
        $session = TrainingSession::create([
            'user_id'      => $user->id,
            'planned_date' => now()->toDateString(),
            'type'         => 'long_run',
            'title'        => 'Langer Lauf',
            'intensity'    => 'medium',
            'distance_km'  => 20,
            'duration_min' => 120,
            'status'       => 'planned',
        ]);

        $this->actingAs($user)
            ->getJson(route('training-sessions.nutrition-tips', $session->id))
            ->assertOk()
            ->assertJsonStructure(['before', 'during', 'after']);
    }

    public function test_the_session_steps_endpoint_answers(): void
    {
        $this->fakeReply(json_encode([
            ['type' => 'warmup',   'label' => 'Einlaufen', 'duration_min' => 10, 'pace_target' => '6:30', 'zone' => 1, 'repetitions' => null],
            ['type' => 'work',     'label' => 'Schwelle',  'duration_min' => 20, 'pace_target' => '5:00', 'zone' => 3, 'repetitions' => null],
            ['type' => 'cooldown', 'label' => 'Auslaufen', 'duration_min' => 10, 'pace_target' => '6:30', 'zone' => 1, 'repetitions' => null],
        ]));

        $user = $this->athlete();
        $session = TrainingSession::create([
            'user_id'      => $user->id,
            'planned_date' => now()->toDateString(),
            'type'         => 'tempo_run',
            'title'        => 'Tempolauf',
            'intensity'    => 'medium',
            'duration_min' => 40,
            'status'       => 'planned',
        ]);

        $this->actingAs($user)
            ->getJson(route('training-sessions.steps', $session->id))
            ->assertOk();
    }

    public function test_the_race_strategy_endpoint_answers(): void
    {
        $this->fakeReply('Starte kontrolliert und steigere ab Kilometer 30.');

        $user  = $this->athlete();
        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Marathon',
            'event_date'          => now()->addDays(5),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);

        $this->actingAs($user)
            ->getJson(route('events.plan.strategy', $event->id))
            ->assertOk();
    }
}
