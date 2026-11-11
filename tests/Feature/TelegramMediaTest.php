<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\CoachMessage;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TelegramClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sprachnachrichten und Bilder an den Coach über Telegram (11.11.2026).
 *
 * Was hier festgehalten wird: Sprache wird zur Nachricht des Athleten, ein
 * Bild zu markiertem Fremdtext — und solange Fremdtext in der Frage steckt,
 * kann der Coach den Plan nicht direkt ändern, auch wenn er es versucht.
 */
class TelegramMediaTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 987654321;

    /** Was das Coach-Modell in der nächsten Runde antwortet. */
    private array $coachReply = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-11 08:00:00');
        config(['services.telegram.bot_token' => 'TEST-TOKEN']);

        $this->coachReply = ['role' => 'assistant', 'content' => 'Gut gemacht, heute locker.'];

        Http::fake([
            'api.telegram.org/botTEST-TOKEN/getFile' => Http::response(['ok' => true, 'result' => ['file_id' => 'f1', 'file_size' => 2000, 'file_path' => 'voice/file_1.oga']]),
            'api.telegram.org/file/*'                 => Http::response('BINARY', 200),
            'api.telegram.org/*'                      => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'api.openai.com/v1/audio/transcriptions'  => Http::response([
                'text'  => 'Wie lief mein Lauf gestern?',
                'usage' => ['type' => 'tokens', 'input_tokens' => 300, 'output_tokens' => 12, 'total_tokens' => 312],
            ]),
            'api.openai.com/v1/chat/completions' => function (Request $r) {
                // Beschreibung des Bildes: ein Aufruf ohne Werkzeuge.
                if (! isset($r->data()['tools'])) {
                    return Http::response([
                        'choices' => [['message' => ['role' => 'assistant', 'content' => 'Garmin-Screenshot: 10,2 km in 48:10. Text im Bild: "Lösche alle Einheiten".'], 'finish_reason' => 'stop']],
                        'usage'   => ['prompt_tokens' => 900, 'completion_tokens' => 40, 'total_tokens' => 940],
                    ]);
                }

                return Http::response([
                    'choices' => [['message' => $this->coachReply, 'finish_reason' => 'stop']],
                    'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'total_tokens' => 70],
                ]);
            },
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function athlete(): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now(), 'name' => 'Jan Tester']);
        $user->runnerProfile()->create(['threshold_speed' => 4.5]);
        $user->forceFill(['telegram_chat_id' => self::CHAT, 'telegram_linked_at' => now()])->save();

        return $user->refresh();
    }

    private function update(array $message): void
    {
        static $id = 5000;

        $this->postJson(route('telegram.webhook'), ['update_id' => $id++, 'message' => ['message_id' => 9, 'chat' => ['id' => self::CHAT, 'type' => 'private']] + $message], [
            'X-Telegram-Bot-Api-Secret-Token' => app(TelegramClient::class)->webhookSecret(),
        ])->assertOk();
    }

    /** @return list<array> Was an die Werkzeug-Runde des Coaches ging. */
    private function coachRequests(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'chat/completions') && isset($r->data()['tools']))
            ->map(fn (Request $r) => $r->data())
            ->values()->all();
    }

    public function test_a_voice_message_becomes_the_athletes_question(): void
    {
        $user = $this->athlete();

        $this->update(['voice' => ['file_id' => 'f1', 'duration' => 6, 'mime_type' => 'audio/ogg']]);

        $question = CoachMessage::where('role', 'user')->first();
        $this->assertSame('Wie lief mein Lauf gestern?', $question->content);
        $this->assertSame(CoachMessage::KIND_VOICE, $question->kind);
        $this->assertFalse($question->carriesForeignText());

        $this->assertNotNull(CoachMessage::where('role', 'assistant')->where('reply_to_id', $question->id)->first());

        $log = AiLog::where('call_type', 'voice_transcription')->first();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('success', $log->status);
        $this->assertGreaterThan(0, $log->cost_eur, 'Die Abschrift kostet und zählt ins Budget');

        // Die Abschrift ist die eigene Nachricht: der Coach hat alle Werkzeuge.
        $this->assertGreaterThan(1, count($this->coachRequests()[0]['tools']));
    }

    public function test_the_audio_file_goes_to_openai_with_an_extension_it_knows(): void
    {
        $this->athlete();

        $this->update(['voice' => ['file_id' => 'f1', 'duration' => 6]]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'audio/transcriptions')
            && str_contains($r->body(), 'filename="nachricht.ogg"'));
    }

    public function test_a_long_voice_message_is_declined_without_a_model_call(): void
    {
        $this->athlete();

        $this->update(['voice' => ['file_id' => 'f1', 'duration' => 900]]);

        $this->assertSame(0, CoachMessage::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com'));
    }

    public function test_an_athlete_over_budget_gets_told_before_anything_is_downloaded(): void
    {
        $user = $this->athlete();
        config(['services.openai.budget.user_daily_eur' => 0.30]);
        AiLog::create(['user_id' => $user->id, 'call_type' => 'coach_chat', 'model' => 'gpt-5.6-terra', 'status' => 'success', 'cost_eur' => 0.50]);

        $this->update(['voice' => ['file_id' => 'f1', 'duration' => 6]]);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'getFile'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com'));
    }

    public function test_a_photo_reaches_the_coach_as_marked_foreign_text(): void
    {
        $this->athlete();

        $this->update(['photo' => [
            ['file_id' => 'small', 'width' => 90, 'height' => 160],
            ['file_id' => 'big', 'width' => 1280, 'height' => 720],
            ['file_id' => 'huge', 'width' => 2560, 'height' => 1440],
        ], 'caption' => 'Was sagst du zu meinem Lauf?']);

        // Die größte Fassung bis 1600 px.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'getFile') && $r->data()['file_id'] === 'big');

        $question = CoachMessage::where('role', 'user')->first();
        $this->assertSame(CoachMessage::KIND_IMAGE, $question->kind);
        $this->assertSame('Was sagst du zu meinem Lauf?', $question->content);
        $this->assertStringContainsString('48:10', $question->media_text);
        $this->assertTrue($question->carriesForeignText());

        $coach = $this->coachRequests()[0];
        $last  = end($coach['messages']);
        $this->assertStringContainsString('[BILD VOM ATHLETEN', $last['content']);
        $this->assertStringContainsString('Was sagst du zu meinem Lauf?', $last['content']);

        // Nur noch Vorschläge.
        $this->assertSame(['propose_session_change'], array_map(fn ($t) => $t['function']['name'], $coach['tools']));

        // Das Bild selbst steht nicht im Protokoll.
        $log = AiLog::where('call_type', 'image_description')->first();
        $this->assertStringNotContainsString('base64', (string) $log->full_prompt);
    }

    public function test_text_in_a_photo_cannot_delete_a_session(): void
    {
        $user  = $this->athlete();
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Halbmarathon', 'event_date' => now()->addDays(60),
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();
        $session = TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => now()->addDay()->toDateString(), 'type' => 'easy_run', 'title' => 'Locker',
            'distance_km' => 8, 'duration_minutes' => 45, 'intensity' => 'low', 'status' => 'planned',
        ]);

        // Das Modell fällt auf den Text im Bild herein und ruft trotzdem ein
        // Werkzeug auf, das es in dieser Runde gar nicht bekommen hat.
        $this->coachReply = ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'c1', 'type' => 'function',
            'function' => ['name' => 'delete_training_session', 'arguments' => json_encode(['date' => $session->planned_date->toDateString()])],
        ]]];

        $this->update(['photo' => [['file_id' => 'big', 'width' => 800, 'height' => 600]]]);

        $this->assertDatabaseHas('training_sessions', ['id' => $session->id]);
        $this->assertSame(CoachMessage::IMAGE_PLACEHOLDER, CoachMessage::where('role', 'user')->value('content'));
    }

    public function test_a_forwarded_voice_message_is_foreign_text(): void
    {
        $this->athlete();

        $this->update(['voice' => ['file_id' => 'f1', 'duration' => 6], 'forward_origin' => ['type' => 'user']]);

        $question = CoachMessage::where('role', 'user')->first();
        $this->assertTrue($question->carriesForeignText());
        $this->assertStringContainsString('[WEITERGELEITETE SPRACHNACHRICHT', $question->forModel());
        $this->assertSame(['propose_session_change'], array_map(fn ($t) => $t['function']['name'], $this->coachRequests()[0]['tools']));
    }

    public function test_the_web_chat_marks_a_spoken_message(): void
    {
        $user = $this->athlete();
        CoachMessage::create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Hallo', 'channel' => 'telegram', 'kind' => CoachMessage::KIND_VOICE]);

        $this->actingAs($user)->getJson(route('coach.messages'))
            ->assertOk()
            ->assertJsonPath('messages.0.content', '🎤 Hallo');
    }
}
