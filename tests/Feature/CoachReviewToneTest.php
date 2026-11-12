<?php

namespace Tests\Feature;

use App\Jobs\GenerateSessionReviewJob;
use App\Models\Activity;
use App\Models\CoachMessage;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Observers\CoachMessageObserver;
use App\Services\Review\ReviewText;
use App\Services\Review\SessionHighlights;
use App\Services\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Die Nachricht nach einer Einheit soll klingen wie von einem Trainer.
 *
 * Gemeldet von Jan (12.11.2026) mit einem Spaziergang: „Spaziergang –
 * Alternativtraining: nicht im Laufplan und keine Abweichung davon. Deine
 * 26 Minuten über 2,04 km mit Ø 91 bpm, maximal 124 bpm und 17 hm waren
 * bei 7 °C, gefühlten 3 °C und Wind …" — Steuerzeilen vorgelesen, alle
 * Messwerte aufgezählt, die Pause als „1 von 6 Läufen" gelesen.
 */
class CoachReviewToneTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 4242;

    private const GOOD = 'Gleiches Tempo wie vor einem Monat, aber weniger Puls. Donnerstag kommt die Schwelle.';

    /** Der echte Text aus Jans Screenshot. */
    private const JANS_EXAMPLE = 'Spaziergang – Alternativtraining: nicht im Laufplan und keine Abweichung davon. Deine 26 Minuten über 2,04 km mit Ø 91 bpm, maximal 124 bpm und 17 hm waren bei 7 °C, gefühlten 3 °C und Wind eine sinnvolle, sehr lockere Ergänzung zum Lauftraining – sie bringt aktive Erholung und Bewegung, ohne nennenswert Erholung zu kosten. Bei 1 von 6 geplanten Laufeinheiten in den letzten vier Wochen und 20 Alternativtrainingseinheiten ist dieser Spaziergang als Belastung okay, ersetzt aber weiterhin keinen Laufumfang; die 2,04 km zählen nicht zu den 12,6 Laufkilometern.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 18:00:00');
        config(['services.telegram.bot_token' => 'TEST-TOKEN']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openAiSays(string ...$reviews): void
    {
        $responses = array_map(fn ($r) => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['review' => $r, 'question' => null, 'options' => []])], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 500, 'completion_tokens' => 80, 'total_tokens' => 580],
        ]), $reviews);

        Http::fake([
            'api.openai.com/*'   => Http::sequence($responses),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    /** @return array{0: User, 1: TrainingPlan} */
    private function athlete(): array
    {
        $user = User::factory()->onboarded()->create(['name' => 'Jan Tester']);
        $user->runnerProfile()->create(['threshold_speed' => 4.5]);

        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Frühjahrs-HM', 'event_date' => now()->addWeeks(20),
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        return [$user->refresh(), $plan];
    }

    private function done(User $user, TrainingPlan $plan, string $type = 'easy_run', ?string $sport = null, int $seconds = 2700, int $meters = 8000): TrainingSession
    {
        $activity = Activity::create([
            'user_id' => $user->id, 'strava_id' => random_int(1000000, 9999999), 'name' => 'Einheit',
            'type' => $sport ?? 'Run', 'start_date' => now()->subHour(), 'distance' => $meters,
            'moving_time' => $seconds, 'elapsed_time' => $seconds, 'average_speed' => $meters / $seconds,
            'average_heartrate' => 138, 'weather' => ['temp_c' => 7, 'description' => 'bewölkt'],
        ]);

        return TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $plan->event_id,
            'planned_date' => now()->toDateString(), 'type' => $sport && $sport !== 'Run' ? 'cross_training' : $type,
            'sport_type' => $sport, 'title' => 'Lockerer Lauf', 'description' => '', 'intensity' => 'low',
            'status' => 'completed', 'activity_id' => $activity->id, 'was_unplanned' => $sport !== null,
        ]);
    }

    private function prompt(): string
    {
        $sent = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), 'api.openai.com'));

        return (string) ($sent[0]->data()['messages'][1]['content'] ?? '');
    }

    // ── Wann überhaupt ───────────────────────────────────────────────────

    public function test_a_walk_gets_no_message(): void
    {
        $this->openAiSays(self::GOOD);
        [$user, $plan] = $this->athlete();
        $walk = $this->done($user, $plan, sport: 'Walk', seconds: 1560, meters: 2040);

        (new GenerateSessionReviewJob($walk->id))->handle(app(\App\Services\AI\SessionContentService::class), app(\App\Services\WeatherService::class), app(\App\Services\TrainingLoadService::class));

        $this->assertNotNull($walk->refresh()->reviewed_at, 'Erledigt, damit der Job nicht wiederkommt');
        $this->assertNull($walk->coach_review);
        $this->assertSame(0, CoachMessage::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com'));
    }

    public function test_a_long_hike_is_training(): void
    {
        $this->assertTrue(SessionHighlights::silent('Hike', 40));
        $this->assertFalse(SessionHighlights::silent('Hike', 150));
        $this->assertFalse(SessionHighlights::silent('Ride', 30));
        $this->assertFalse(SessionHighlights::silent(null, 30));
    }

    // ── Worüber ──────────────────────────────────────────────────────────

    public function test_a_plain_easy_run_is_a_short_routine_message(): void
    {
        $h = SessionHighlights::pick(['run' => true, 'type' => 'easy_run']);

        $this->assertSame('routine', $h['key']);
        $this->assertSame('short', $h['length']);
    }

    public function test_a_personal_best_comes_first(): void
    {
        $h = SessionHighlights::pick([
            'run' => true, 'type' => 'tempo_run', 'pace_off_s' => -15,
            'pb' => ['distance' => '10 km', 'time' => '39:50', 'previous' => '40:31'],
        ]);

        $this->assertSame('pb', $h['key']);
        $this->assertTrue($h['emoji']);
        $this->assertNotNull($h['side'], 'Das zu schnelle Tempo bleibt als Nebenpunkt');
    }

    public function test_a_key_session_run_too_fast_is_the_point(): void
    {
        $this->assertSame('too_fast', SessionHighlights::pick(['run' => true, 'type' => 'tempo_run', 'pace_off_s' => -12])['key']);
        $this->assertNotSame('too_fast', SessionHighlights::pick(['run' => true, 'type' => 'easy_run', 'pace_off_s' => -12])['key'],
            'Ein lockerer Lauf ein paar Sekunden schneller ist kein Thema');
    }

    public function test_the_first_run_after_a_break_is_the_point(): void
    {
        $this->assertSame('comeback', SessionHighlights::pick(['run' => true, 'type' => 'easy_run', 'comeback_days' => 18])['key']);
    }

    public function test_same_pace_lower_heart_rate_is_progress(): void
    {
        $this->assertSame('fitter', SessionHighlights::pick(['run' => true, 'type' => 'easy_run', 'trend_pace_s' => 1, 'trend_hr' => -6])['key']);
    }

    // ── Wie ──────────────────────────────────────────────────────────────

    public function test_jans_example_fails_the_check(): void
    {
        $problems = implode(' | ', ReviewText::problems(self::JANS_EXAMPLE, 2, false));

        $this->assertStringContainsString('Zahlen', $problems);
        $this->assertStringContainsString('Alternativtraining', $problems);
        $this->assertStringContainsString('bpm', $problems);
        $this->assertStringContainsString('Wörter', $problems);
    }

    public function test_a_coach_like_message_passes(): void
    {
        $this->assertSame([], ReviewText::problems(self::GOOD, 2, false));
        $this->assertSame([], ReviewText::problems('Neue Bestzeit über 10 km, 39:50! Jetzt zwei Tage locker. 🎉', 3, true));
    }

    public function test_a_time_like_4_15_does_not_split_a_sentence(): void
    {
        $this->assertCount(1, ReviewText::sentences('Nächstes Mal genau auf 4:15 bleiben.'));
    }

    // ── Der Ablauf ───────────────────────────────────────────────────────

    public function test_the_prompt_names_the_point_the_goal_and_the_next_session(): void
    {
        $this->openAiSays(self::GOOD);
        [$user, $plan] = $this->athlete();
        TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $plan->event_id,
            'planned_date' => now()->addDays(2)->toDateString(), 'type' => 'tempo_run', 'title' => 'Schwelle',
            'description' => '', 'intensity' => 'high', 'status' => 'planned',
        ]);
        $run = $this->done($user, $plan);

        GenerateSessionReviewJob::dispatchSync($run->id);

        $prompt = $this->prompt();
        $this->assertStringContainsString('Hauptpunkt:', $prompt);
        $this->assertStringContainsString('Ziel: Frühjahrs-HM', $prompt);
        $this->assertStringContainsString('Nächste Einheit: Samstag, Tempolauf (Schwelle)', $prompt);
        $this->assertStringContainsString('Vorname: Jan', $prompt);
        $this->assertStringNotContainsString('EINORDNUNG', $prompt);
        $this->assertSame(self::GOOD, $run->refresh()->coach_review);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com')
            && $r->data()['model'] === config('services.openai.model_mid', $r->data()['model']));
    }

    public function test_a_message_that_reads_like_a_log_is_rewritten_once(): void
    {
        $this->openAiSays(self::JANS_EXAMPLE, self::GOOD);
        [$user, $plan] = $this->athlete();
        $run = $this->done($user, $plan);

        GenerateSessionReviewJob::dispatchSync($run->id);

        $this->assertSame(self::GOOD, $run->refresh()->coach_review);
        $this->assertCount(2, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.openai.com')));
    }

    public function test_during_a_break_the_plan_quote_is_not_reported(): void
    {
        $this->openAiSays(self::GOOD);
        [$user, $plan] = $this->athlete();
        $user->runnerProfile->update(['training_paused_until' => now()->addDays(3), 'training_pause_reason' => 'OP']);
        foreach (range(3, 20, 3) as $d) {
            TrainingSession::create([
                'user_id' => $user->id, 'training_plan_id' => $plan->id, 'planned_date' => now()->subDays($d)->toDateString(),
                'type' => 'easy_run', 'title' => 'Locker', 'description' => '', 'intensity' => 'low', 'status' => 'skipped',
            ]);
        }
        $ride = $this->done($user, $plan, sport: 'Ride', seconds: 3000, meters: 20000);

        GenerateSessionReviewJob::dispatchSync($ride->id);

        $prompt = $this->prompt();
        $this->assertStringNotContainsString('Umsetzung der letzten 4 Wochen', $prompt);
        $this->assertStringContainsString('Trainingspause bis', $prompt);
    }

    // ── Telegram ─────────────────────────────────────────────────────────

    public function test_the_follow_up_question_comes_with_buttons(): void
    {
        $this->openAiSays(self::GOOD);
        [$user, $plan] = $this->athlete();
        $user->forceFill(['telegram_chat_id' => self::CHAT, 'telegram_linked_at' => now()])->save();
        $run = $this->done($user, $plan);
        $run->update(['review_question' => 'Hast du unterwegs getrunken?', 'review_options' => ['Ja', 'Kaum', 'Nein']]);

        $message = CoachMessage::create([
            'user_id' => $user->id, 'role' => 'assistant', 'content' => "Puls stieg hinten raus.\n\nHast du unterwegs getrunken?",
            'actions' => [['type' => CoachMessage::ACTION_REVIEW_QUESTION, 'session_id' => $run->id]],
        ]);

        $buttons = CoachMessageObserver::reviewButtons($message);
        $this->assertSame("rv:{$run->id}:1", $buttons[0][1]['callback_data']);
        $this->assertSame('Nein', $buttons[1][0]['text']);

        $sent = collect(Http::recorded())->first(fn ($p) => str_ends_with($p[0]->url(), '/sendMessage'));
        $this->assertSame("rv:{$run->id}:0", $sent[0]->data()['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function test_tapping_an_answer_stores_it_and_asks_the_coach(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'api.openai.com/*'   => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Dann beim nächsten Langen eine Flasche mitnehmen.'], 'finish_reason' => 'stop']],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'total_tokens' => 70],
            ]),
        ]);
        [$user, $plan] = $this->athlete();
        $user->forceFill(['telegram_chat_id' => self::CHAT, 'telegram_linked_at' => now()])->save();
        $run = $this->done($user, $plan);
        $run->update(['review_question' => 'Hast du unterwegs getrunken?', 'review_options' => ['Ja', 'Kaum', 'Nein']]);

        $this->postJson(route('telegram.webhook'), ['update_id' => 9001, 'callback_query' => [
            'id' => 'cb', 'data' => "rv:{$run->id}:1",
            'message' => ['message_id' => 50, 'chat' => ['id' => self::CHAT, 'type' => 'private']],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => app(TelegramClient::class)->webhookSecret()])->assertOk();

        $this->assertSame('Kaum', $run->refresh()->review_feedback);
        $this->assertStringContainsString('Kaum', $user->runnerProfile->refresh()->coach_notes);
        $this->assertTrue(CoachMessage::where('role', 'user')->where('content', 'like', '%Kaum%')->exists());
    }

    public function test_the_web_chat_hides_the_question_marker(): void
    {
        $vue = file_get_contents(resource_path('js/Components/CoachSlideOver.vue'));

        $this->assertStringContainsString("a.type !== 'review_question'", $vue);
    }
}
