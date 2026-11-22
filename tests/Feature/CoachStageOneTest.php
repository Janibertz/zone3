<?php

namespace Tests\Feature;

use App\Jobs\CoachReplyJob;
use App\Models\Activity;
use App\Models\AiLog;
use App\Models\AppSetting;
use App\Models\CoachMessage;
use App\Models\CoachTurn;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use App\Services\AI\OpenAIClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Stufe 0 und 1 des neuen Coaches (22.11.2026, Konzept „Coach-Chat neu“):
 * keine Strava-Daten im Kontext, alle Einheiten von heute mit ID, Verlauf
 * mit Protokoll, Responses API mit Reasoning und eine Zeile `coach_turns`.
 */
class CoachStageOneTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-22';

    /** @var list<array> */
    private array $requests = [];

    /** @var list<array> Antworten von /v1/responses, der Reihe nach */
    private array $outputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY . ' 11:55:00');
        config(['services.openai.api_key' => 'test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeChat(): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => function (Request $r) {
            $this->requests[] = $r->data();

            return Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Alles klar.'], 'finish_reason' => 'stop']],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'total_tokens' => 70],
            ]);
        }]);
    }

    private function fakeResponses(): void
    {
        AppSetting::set('coach_engine', 'responses');

        Http::fake(['api.openai.com/v1/responses' => function (Request $r) {
            $this->requests[] = $r->data();
            $output = array_shift($this->outputs) ?? [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Erledigt.']]]];

            return Http::response([
                'id'     => 'resp_' . count($this->requests),
                'status' => 'completed',
                'output' => $output,
                'usage'  => [
                    'input_tokens' => 1000, 'output_tokens' => 200, 'total_tokens' => 1200,
                    'input_tokens_details'  => ['cached_tokens' => 800],
                    'output_tokens_details' => ['reasoning_tokens' => 150],
                ],
            ]);
        }]);
    }

    /** Jans Sonntag: Longrun erledigt (Garmin), Rad locker 30 min geplant. */
    private function jansSunday(): array
    {
        $user  = User::factory()->onboarded()->create();
        $event = Event::create(['user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11', 'race_distance' => 'half_marathon', 'priority' => 'A']);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);
        RunnerProfile::create(['user_id' => $user->id]);

        $run = Activity::create([
            'user_id' => $user->id, 'source' => Activity::SOURCE_GARMIN, 'garmin_activity_id' => 111,
            'name' => 'Longrun', 'type' => 'Run', 'start_date' => self::TODAY . ' 08:00:00',
            'distance' => 18700, 'moving_time' => 6300, 'elapsed_time' => 6400, 'average_speed' => 2.97,
        ]);
        $long = TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id, 'planned_date' => self::TODAY,
            'type' => 'long_run', 'title' => 'Langer Lauf', 'description' => '', 'intensity' => 'moderate',
            'status' => 'completed', 'sort_order' => 1, 'distance_km' => 18.7, 'activity_id' => $run->id,
        ]);
        $bike = TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id, 'planned_date' => self::TODAY,
            'type' => 'cross_training', 'sport_type' => 'VirtualRide', 'title' => 'Rad locker', 'description' => '',
            'intensity' => 'low', 'status' => 'planned', 'sort_order' => 2, 'duration_min' => 30,
        ]);

        return [$user->fresh(), $long, $bike];
    }

    private function systemPrompt(): string
    {
        $first = $this->requests[0];

        return $first['instructions'] ?? $first['messages'][0]['content'];
    }

    public function test_strava_only_runs_never_reach_the_prompt(): void
    {
        $this->fakeChat();
        [$user] = $this->jansSunday();

        Activity::create([
            'user_id' => $user->id, 'source' => Activity::SOURCE_STRAVA, 'strava_id' => 999,
            'name' => 'Strava Morgenrunde', 'type' => 'Run', 'start_date' => '2026-11-20 07:00:00',
            'distance' => 10000, 'moving_time' => 3000, 'elapsed_time' => 3000, 'average_speed' => 3.33,
        ]);

        app(CoachChatService::class)->chatWithCoachTools($user, [], 'Wie war meine Woche?');

        $prompt = $this->systemPrompt();
        $this->assertStringNotContainsString('Strava Morgenrunde', $prompt);
        $this->assertStringContainsString('"Longrun"', $prompt);
        $this->assertStringContainsString('Aktuelle Woche: 18.7 km', $prompt);
    }

    public function test_a_session_completed_from_strava_shows_no_numbers(): void
    {
        $this->fakeChat();
        [$user, $long] = $this->jansSunday();

        $strava = Activity::create([
            'user_id' => $user->id, 'source' => Activity::SOURCE_STRAVA, 'strava_id' => 998,
            'name' => 'Lauf', 'type' => 'Run', 'start_date' => self::TODAY . ' 08:00:00',
            'distance' => 18700, 'moving_time' => 6300, 'elapsed_time' => 6400, 'average_speed' => 2.97,
        ]);
        $long->update(['activity_id' => $strava->id]);

        app(CoachChatService::class)->chatWithCoachTools($user, [], 'Wie lief es?');

        $prompt = $this->systemPrompt();
        $this->assertStringContainsString("[ID:{$long->id}] erledigt — Typ:long_run", $prompt);
        $this->assertStringContainsString('(Zahlen nicht verfügbar)', $prompt);
        $this->assertStringNotContainsString('18.7km', $prompt);
    }

    public function test_every_session_of_today_is_in_the_context_with_its_id(): void
    {
        $this->fakeChat();
        [$user, $long, $bike] = $this->jansSunday();

        app(CoachChatService::class)->chatWithCoachTools($user, [], 'Rad bitte streichen');

        $prompt = $this->systemPrompt();
        $this->assertStringContainsString('HEUTE (2 Einheiten', $prompt);
        $this->assertStringContainsString("[ID:{$long->id}] erledigt", $prompt);
        $this->assertStringContainsString("[ID:{$bike->id}] geplant — Typ:cross_training, Sport:VirtualRide", $prompt);
    }

    public function test_the_history_says_what_was_really_changed(): void
    {
        $user = User::factory()->create();
        $q    = CoachMessage::create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Heute Ruhetag', 'channel' => 'telegram']);
        $said = CoachMessage::create(['user_id' => $user->id, 'role' => 'assistant', 'content' => 'Heute ist Ruhetag.', 'channel' => 'telegram', 'reply_to_id' => $q->id]);
        $done = CoachMessage::create(['user_id' => $user->id, 'role' => 'assistant', 'content' => 'Erledigt.', 'channel' => 'telegram', 'reply_to_id' => $q->id,
            'actions' => [['type' => 'rest_day', 'label' => 'Ruhetag am 22.11.']]]);
        $review = CoachMessage::create(['user_id' => $user->id, 'role' => 'assistant', 'content' => 'Starker Lauf!']);

        $this->assertStringContainsString('[Zone3-Protokoll: Bei dieser Antwort wurde nichts am Plan geändert.]', $said->forHistory());
        $this->assertStringContainsString('[Zone3-Protokoll: Eingetragen wurde: Ruhetag am 22.11.]', $done->forHistory());
        $this->assertSame('Starker Lauf!', $review->forHistory());
        $this->assertSame('Heute Ruhetag', $q->forHistory());
    }

    public function test_the_responses_engine_thinks_calls_a_tool_and_passes_the_reasoning_back(): void
    {
        $this->fakeResponses();
        [$user, $long, $bike] = $this->jansSunday();

        $reasoning = ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'gAAA…', 'summary' => []];
        $this->outputs = [
            [$reasoning, ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'delete_training_session',
                'arguments' => json_encode(['date' => self::TODAY, 'type' => 'cross_training'])]],
            [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Das Rad ist gestrichen, der Longrun bleibt.']]]],
        ];

        $q = CoachMessage::create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Die 30 min Rad von heute bitte streichen', 'channel' => 'telegram']);
        (new CoachReplyJob($q->id))->handle(app(CoachChatService::class));

        // Das Rad ist weg, der erledigte Longrun steht.
        $this->assertNull(TrainingSession::find($bike->id));
        $this->assertSame('completed', $long->fresh()->status);

        // Erster Aufruf: Reasoning an, nichts bei OpenAI gespeichert, Werkzeuge flach.
        $first = $this->requests[0];
        $this->assertSame('low', $first['reasoning']['effort']);
        $this->assertFalse($first['store']);
        $this->assertSame(['reasoning.encrypted_content'], $first['include']);
        $this->assertStringContainsString('ATHLETEN-DATEN', $first['instructions']);
        $this->assertSame('delete_training_session', collect($first['tools'])->firstWhere('name', 'delete_training_session')['name']);
        $this->assertArrayNotHasKey('function', $first['tools'][0]);

        // Zweiter Aufruf: Reasoning, Aufruf und Ergebnis gehen zurück.
        $types = array_column($this->requests[1]['input'], 'type');
        $this->assertContains('reasoning', $types);
        $this->assertContains('function_call', $types);
        $this->assertContains('function_call_output', $types);

        // Die Antwort kommt an, mit dem Protokoll der Änderung.
        $reply = CoachMessage::where('reply_to_id', $q->id)->first();
        $this->assertSame('Das Rad ist gestrichen, der Longrun bleibt.', $reply->content);
        $this->assertSame('session_deleted', $reply->actions[0]['type']);

        // Kosten mit Cache und Reasoning im AI-Log.
        $log = AiLog::where('call_type', 'coach_chat')->first();
        $this->assertSame(1000, $log->prompt_tokens);
        $this->assertSame(800, $log->cached_tokens);
        $this->assertSame(150, $log->reasoning_tokens);

        // Eine Zeile coach_turns.
        $turn = CoachTurn::first();
        $this->assertSame('responses', $turn->engine);
        $this->assertSame(2, $turn->steps);
        $this->assertSame(['delete_training_session'], $turn->tools);
        $this->assertSame(1, $turn->changes);
        $this->assertSame($reply->id, $turn->reply_id);
        $this->assertGreaterThan(0, $turn->cost_eur);
    }

    public function test_the_switch_falls_back_to_chat_completions(): void
    {
        AppSetting::set('coach_engine', 'chat');
        $this->assertSame('chat', OpenAIClient::coachEngine());

        AppSetting::set('coach_engine', 'responses');
        $this->assertSame('responses', OpenAIClient::coachEngine());
    }

    public function test_an_incomplete_response_reads_as_cut_off(): void
    {
        $body = OpenAIClient::normalizeResponses(['status' => 'incomplete', 'output' => [], 'usage' => []]);

        $this->assertSame('length', $body['choices'][0]['finish_reason']);
        $this->assertNull($body['choices'][0]['message']['content']);
    }
}
