<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gemeldet am 06.11.2026: „am Sonntag möchte ich 30 Min locker auf Zwift
 * fahren" — im Plan stand danach ein lockerer LAUF, 5,5 km in 4:59–5:58.
 * Auf die Korrektur antwortete der Coach mit seiner eigenen Überlegung
 * („We need respond presumably correct prior mistake? … Use tool.").
 */
class CoachBikeSessionTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY  = '2026-11-06';
    private const SUNDAY = '2026-11-08';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY);
        CarbonImmutable::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function athlete(?int $ftp = 250): array
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->create(['threshold_speed' => 4.5, 'ftp' => $ftp]);

        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        return [$user->refresh(), $plan];
    }

    private function tool(User $user, string $name, array $args): array
    {
        $method = new \ReflectionMethod(CoachChatService::class, 'executeCoachTool');
        $method->setAccessible(true);

        return $method->invoke(app(CoachChatService::class), $user->fresh(), $name, $args);
    }

    private function plannedOn(User $user, TrainingPlan $plan, array $attrs): TrainingSession
    {
        return TrainingSession::create($attrs + [
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $plan->event_id,
            'planned_date' => self::SUNDAY, 'description' => '', 'status' => 'planned',
        ]);
    }

    // ── Radeinheit anlegen ───────────────────────────────────────────────

    public function test_thirty_minutes_on_zwift_become_a_ride_not_a_run(): void
    {
        [$user, $plan] = $this->athlete();

        $result = $this->tool($user, 'create_bike_session', ['date' => self::SUNDAY, 'duration_min' => 30]);

        $this->assertNotNull($result['action']);
        $ride = TrainingSession::where('user_id', $user->id)->sole();
        $this->assertSame('cross_training', $ride->type);
        $this->assertSame('VirtualRide', $ride->sport_type);
        $this->assertSame(30, $ride->duration_min);
        $this->assertNull($ride->distance_km);
        $this->assertNull($ride->pace_target);
        $this->assertSame($plan->id, $ride->training_plan_id, 'sonst fehlt sie auf der Planseite');
        $this->assertNotNull($ride->pinned_at);
        $this->assertTrue($ride->hasBikeSteps(), 'Schritte in % FTP, sonst gibt es keine .zwo-Datei');
    }

    public function test_a_ride_comes_in_addition_to_a_run_on_that_day(): void
    {
        [$user, $plan] = $this->athlete();
        $run = $this->plannedOn($user, $plan, ['type' => 'long_run', 'title' => 'Langer Lauf', 'duration_min' => 90, 'intensity' => 'low']);

        $result = $this->tool($user, 'create_bike_session', ['date' => self::SUNDAY, 'duration_min' => 30]);

        $this->assertSame('long_run', $run->fresh()->type);
        $this->assertSame(2, TrainingSession::where('user_id', $user->id)->count());
        $this->assertStringContainsString('bleibt stehen', $result['message'], 'das Modell soll es dem Athleten sagen');
    }

    public function test_a_second_ride_on_the_same_day_replaces_the_first(): void
    {
        [$user] = $this->athlete();

        $this->tool($user, 'create_bike_session', ['date' => self::SUNDAY, 'duration_min' => 30]);
        $this->tool($user, 'create_bike_session', ['date' => self::SUNDAY, 'duration_min' => 60, 'workout' => 'vo2']);

        $ride = TrainingSession::where('user_id', $user->id)->sole();
        $this->assertSame('high', $ride->intensity);
    }

    public function test_a_ride_replaces_a_rest_day(): void
    {
        [$user, $plan] = $this->athlete();
        $this->plannedOn($user, $plan, ['type' => 'rest', 'title' => 'Ruhetag', 'intensity' => 'rest']);

        $this->tool($user, 'create_bike_session', ['date' => self::SUNDAY, 'duration_min' => 30]);

        $this->assertSame(['cross_training'], TrainingSession::where('user_id', $user->id)->pluck('type')->all());
    }

    public function test_no_ride_in_the_past(): void
    {
        [$user] = $this->athlete();

        $result = $this->tool($user, 'create_bike_session', ['date' => '2026-11-01', 'duration_min' => 30]);

        $this->assertNull($result['action']);
        $this->assertSame(0, TrainingSession::where('user_id', $user->id)->count());
    }

    /** Genau der gemeldete Aufruf: easy_run mit „Zwift" im Titel. */
    public function test_a_run_titled_zwift_is_refused_and_points_to_the_bike_tool(): void
    {
        [$user] = $this->athlete();

        $result = $this->tool($user, 'create_training_session', [
            'date' => self::SUNDAY, 'type' => 'easy_run', 'title' => 'Zwift locker – 30 Min', 'duration_min' => 30,
        ]);

        $this->assertNull($result['action']);
        $this->assertStringContainsString('create_bike_session', $result['message']);
        $this->assertSame(0, TrainingSession::where('user_id', $user->id)->count());
    }

    public function test_an_ordinary_run_is_still_created(): void
    {
        [$user] = $this->athlete();

        $result = $this->tool($user, 'create_training_session', [
            'date' => self::SUNDAY, 'type' => 'easy_run', 'title' => 'Lockerer Lauf durch den Grad-Park', 'duration_min' => 40,
        ]);

        $this->assertNotNull($result['action']);
    }

    /** Die Korrektur: der falsche Lauf wird mit genau den genannten 30 min aufs Rad gelegt. */
    public function test_switch_to_bike_keeps_the_minutes_the_athlete_named(): void
    {
        [$user, $plan] = $this->athlete();
        $run = $this->plannedOn($user, $plan, [
            'type' => 'easy_run', 'title' => 'Zwift locker – 30 Min', 'duration_min' => 30,
            'distance_km' => 5.5, 'pace_target' => '4:59–5:58', 'intensity' => 'low',
        ]);

        $this->tool($user, 'switch_to_bike', ['date' => self::SUNDAY, 'duration_min' => 30]);

        $run->refresh();
        $this->assertSame('VirtualRide', $run->sport_type);
        $this->assertSame(30, $run->duration_min, 'nicht 30 × 1,25');
    }

    // ── Überlegung statt Antwort ─────────────────────────────────────────

    private const LEAK = 'We need respond presumably correct prior mistake? The next 7 says Zwift locker 30 min easy_run weird. '
        . 'User clarifies. Need tool? There is no create bike; switch_to_bike turns planned run into bike. '
        . 'Sunday planned "Zwift locker – 30 Min (easy_run, 5.5km)" likely already bike mislabeled? Need call switch_to_bike 2026-11-08. Then state. Use tool.';

    private static function text(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ];
    }

    private static function toolCall(string $name, array $args, string $finish = 'tool_calls'): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant', 'content' => null,
                    'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]]],
                ],
                'finish_reason' => $finish,
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ];
    }

    private function ask(User $user, string $message): string
    {
        $sent = $this->actingAs($user)->postJson(route('coach.send'), ['message' => $message])->assertStatus(202);

        return (string) $this->getJson(route('coach.reply', $sent->json('message_id')))
            ->assertOk()
            ->assertJsonPath('pending', false)
            ->json('response');
    }

    public function test_leaked_reasoning_never_reaches_the_athlete_and_the_tool_still_runs(): void
    {
        [$user] = $this->athlete();

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(self::text(self::LEAK))
            ->push(self::toolCall('create_bike_session', ['date' => self::SUNDAY, 'duration_min' => 30]))
            ->push(self::text('Erledigt, am Sonntag steht jetzt 30 min lockeres Rad auf Zwift.'))]);

        $reply = $this->ask($user, 'Nein, keine Laufeinheit, sondern 30 min Rad auf Zwift.');

        $this->assertSame('Erledigt, am Sonntag steht jetzt 30 min lockeres Rad auf Zwift.', $reply);
        $this->assertSame('VirtualRide', TrainingSession::where('user_id', $user->id)->sole()->sport_type);
        $this->assertDatabaseMissing('coach_messages', ['content' => self::LEAK]);
    }

    public function test_reasoning_twice_falls_back_instead_of_showing_it(): void
    {
        [$user] = $this->athlete();

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(self::text(self::LEAK))
            ->push(self::text('The user wants a bike. We should call the tool and then answer.'))]);

        $reply = $this->ask($user, 'Rad bitte.');

        $this->assertStringNotContainsString('We', $reply);
        $this->assertStringContainsString('Entschuldigung', $reply);
        Http::assertSentCount(2);
    }

    /** Ein Werkzeugaufruf mit finish_reason „stop" ging bisher verloren. */
    public function test_a_tool_call_counts_even_without_the_tool_calls_finish_reason(): void
    {
        [$user] = $this->athlete();

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(self::toolCall('create_bike_session', ['date' => self::SUNDAY, 'duration_min' => 30], finish: 'stop'))
            ->push(self::text('Steht drin.'))]);

        $this->assertSame('Steht drin.', $this->ask($user, 'Sonntag 30 min Zwift.'));
        $this->assertSame(1, TrainingSession::where('user_id', $user->id)->count());
    }

    public function test_the_ai_log_shows_which_tool_was_called(): void
    {
        [$user] = $this->athlete();

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(self::toolCall('create_bike_session', ['date' => self::SUNDAY, 'duration_min' => 30]))
            ->push(self::text('Steht drin.'))]);

        $this->ask($user, 'Sonntag 30 min Zwift.');

        $this->assertDatabaseHas('ai_logs', ['call_type' => 'coach_chat', 'full_response' => "[Werkzeug] create_bike_session {\"date\":\"2026-11-08\",\"duration_min\":30}\n[finish_reason] tool_calls"]);
    }

    public static function germanReplies(): array
    {
        return [
            'kurz'          => ['Erledigt!'],
            'mit Anglizismen' => ['Für Sonntag, 08.11., steht jetzt eine lockere 30-Minuten-Zwift-Einheit im Plan. Ganz entspannt, kein Easy Run, sondern Rad.'],
            'Markdown'      => ["**Heute:** Tempolauf\n- 10 min Einlaufen\n- 3×8 min Schwelle\n\nDu packst das!"],
            'Englisch zitiert' => ['Dein Garmin sagt „Training Status: productive“ — das passt zu deiner Woche.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('germanReplies')]
    public function test_ordinary_german_replies_are_not_mistaken_for_reasoning(string $reply): void
    {
        $this->assertFalse(CoachChatService::looksLikeLeakedReasoning($reply));
    }

    public function test_the_reported_reply_is_recognised(): void
    {
        $this->assertTrue(CoachChatService::looksLikeLeakedReasoning(self::LEAK));
        $this->assertTrue(CoachChatService::looksLikeLeakedReasoning('User clarifies they want bike. Call switch_to_bike for Sunday then confirm.'));
    }
}
