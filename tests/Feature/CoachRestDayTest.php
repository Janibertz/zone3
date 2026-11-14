<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\SessionRecommendation;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use App\Services\LiveActivity\LiveActivityService;
use App\Services\Widget\WidgetPayload;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * „Heute Ruhetag“ im Coach-Chat (Jan, 14.11.2026): der Coach bestätigte
 * dreimal im Text, rief kein Werkzeug auf, und „Heute“, Widget und Live
 * Activity zeigten weiter den lockeren Lauf.
 */
class CoachRestDayTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-14';

    /** @var list<array> Antworten des Modells, der Reihe nach */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY . ' 07:30:00');
        config(['services.openai.api_key' => 'test']);
        $this->withoutVite();

        Http::fake(['api.openai.com/v1/chat/completions' => function (Request $r) {
            $msg = array_shift($this->replies) ?? ['role' => 'assistant', 'content' => 'Ruhetag ist eingetragen.'];

            return Http::response([
                'choices' => [['message' => $msg, 'finish_reason' => isset($msg['tool_calls']) ? 'tool_calls' : 'stop']],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'total_tokens' => 70],
            ]);
        }]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Plan startet erst am 30.11., heute steht nur der Tagesvorschlag. */
    private function athleteBeforePlanStart(): array
    {
        $user  = User::factory()->onboarded()->create();
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'plan_start_date' => '2026-11-30',
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);
        RunnerProfile::create([
            'user_id' => $user->id,
            'recommendation_date' => self::TODAY,
            'today_recommendation' => json_encode(['type' => 'easy_run', 'title' => 'Lockerer Erhalt-Lauf', 'distance_km' => 5.7, 'duration_min' => 30, 'zone' => 2, 'intensity' => 'low']),
        ]);

        return [$user->fresh(), $plan];
    }

    private function toolCall(string $name, array $args): array
    {
        return ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'call_1', 'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($args)],
        ]]];
    }

    private function assertRestEverywhere(User $user): void
    {
        $user = $user->fresh();
        $day  = CarbonImmutable::today();

        // Live Activity: nichts mehr zu zeigen.
        $this->assertNull(app(LiveActivityService::class)->state($user, $day));

        // Widget: heute nur der Ruhetag, kein Vorschlag mehr.
        $items = app(WidgetPayload::class)->itemsOn($user, $day);
        $this->assertSame(['rest'], array_column($items, 'type'));
        $this->assertNull(app(WidgetPayload::class)->suggestionFor($user, $day));

        // „Heute“: der Aufmacher ist der Ruhetag.
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('todayPlanSession.type', 'rest')
            ->where('todayPlanSession.is_today', true));

        // Der Tagesvorschlag selbst ist ein Ruhetag (Verfügbarkeit heute aus).
        [$rec] = app(\App\Http\Controllers\AIController::class)->todayFor($user, requireWellbeing: false);
        $this->assertSame('rest', $rec['type']);
    }

    public function test_a_coach_that_only_confirms_is_nudged_and_the_rest_day_lands_everywhere(): void
    {
        [$user] = $this->athleteBeforePlanStart();

        $this->replies = [
            ['role' => 'assistant', 'content' => 'Heute ist Ruhetag, Jan. Gute Entscheidung.'],
            $this->toolCall('set_rest_day', ['date' => self::TODAY]),
            ['role' => 'assistant', 'content' => 'Erledigt — heute Ruhetag.'],
        ];

        $result = app(CoachChatService::class)->chatWithCoachTools($user, [
            ['role' => 'user', 'content' => 'Heute Ruhetag bitte'],
            ['role' => 'assistant', 'content' => 'Heute ist Ruhetag, Jan.'],
        ], 'Heute bitte Ruhetag');

        $this->assertSame('rest_day', $result['actions'][0]['type']);
        $this->assertRestEverywhere($user);
    }

    public function test_in_a_running_plan_all_of_todays_sessions_are_struck(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = Event::create(['user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11', 'race_distance' => 'half_marathon', 'priority' => 'A']);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);
        RunnerProfile::create(['user_id' => $user->id]);
        $run = TrainingSession::create(['user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id, 'planned_date' => self::TODAY, 'type' => 'interval', 'title' => '5×1000', 'description' => '', 'intensity' => 'high', 'status' => 'planned', 'sort_order' => 1, 'duration_min' => 55]);
        $gym = TrainingSession::create(['user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id, 'planned_date' => self::TODAY, 'type' => 'strength', 'title' => 'Kraft', 'description' => '', 'intensity' => 'moderate', 'status' => 'planned', 'sort_order' => 2, 'duration_min' => 30]);
        $rec = SessionRecommendation::create(['user_id' => $user->id, 'training_session_id' => $run->id, 'before' => [], 'after' => ['type' => 'easy_run'], 'reason' => 'müde', 'status' => SessionRecommendation::STATUS_PENDING]);

        $this->replies = [$this->toolCall('set_rest_day', ['reason' => 'müde'])];
        app(CoachChatService::class)->chatWithCoachTools($user->fresh(), [], 'Ich möchte heute einen Ruhetag einlegen');

        $this->assertSame('skipped', $run->fresh()->status);
        $this->assertSame('skipped', $gym->fresh()->status);
        $this->assertSame(SessionRecommendation::STATUS_EXPIRED, $rec->fresh()->status);
        $this->assertNotNull(TrainingSession::where('user_id', $user->id)->whereDate('planned_date', self::TODAY)->where('type', 'rest')->value('pinned_at'));
        $this->assertFalse($plan->fresh()->availability_overrides[self::TODAY]['available']);
        $this->assertRestEverywhere($user);
    }

    public function test_the_rest_intent_is_recognised_narrowly(): void
    {
        foreach (['Heute Ruhetag bitte', 'Ich möchte heute einen Ruhetag einlegen', 'heute bitte Pause', 'Heute nicht laufen', 'Streich die Einheit heute'] as $m) {
            $this->assertTrue(CoachChatService::asksForRestDay($m), $m);
        }
        foreach (['Wie lief mein Lauf gestern?', 'Soll ich morgen schneller laufen?', 'Brauche ich einen Ruhetag?'] as $m) {
            $this->assertFalse(CoachChatService::asksForRestDay($m), $m);
        }
    }
}
