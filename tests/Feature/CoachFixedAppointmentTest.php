<?php

namespace Tests\Feature;

use App\Jobs\CoachReplyJob;
use App\Models\AppSetting;
use App\Models\CoachChange;
use App\Models\CoachMessage;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use App\Services\Plan\FixedAppointment;
use App\Services\TelegramBriefing;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gemeldet am 24.11.2026 über Telegram: „Ich gehe heute nicht zum Laufclub,
 * ich gehe als Alternative schwimmen.“ Der Plan startete erst am 30.11.,
 * der Laufclub von heute stand nur als fester Termin da. Der Coach strich
 * den Laufclub am 01.12., trug kein Schwimmen ein, und das Briefing sagte
 * „Heute steht nichts im Plan“.
 */
class CoachFixedAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-24'; // Dienstag

    private array $requests = [];
    private array $script = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY . ' 06:40:00');
        config(['services.openai.api_key' => 'test', 'services.openai.coach_tools' => 'v2']);
        AppSetting::set('coach_engine', 'chat');

        Http::fake(['api.openai.com/v1/chat/completions' => function (Request $r) {
            $this->requests[] = $r->data();
            $next = array_shift($this->script) ?? 'Alles klar.';
            $message = is_string($next)
                ? ['role' => 'assistant', 'content' => $next]
                : ['role' => 'assistant', 'content' => null, 'tool_calls' => array_map(fn ($c, $i) => [
                    'id' => 'call_' . count($this->requests) . "_{$i}", 'type' => 'function',
                    'function' => ['name' => $c[0], 'arguments' => json_encode($c[1])],
                ], $next, array_keys($next))];

            return Http::response([
                'choices' => [['message' => $message, 'finish_reason' => is_string($next) ? 'stop' : 'tool_calls']],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'total_tokens' => 70],
            ]);
        }]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Plan ab 30.11., dienstags Laufclub; am 01.12. steht er als Planeinheit. */
    private function jan(): array
    {
        $user  = User::factory()->onboarded()->create(['name' => 'Jan Test']);
        $event = Event::create(['user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11', 'race_distance' => 'half_marathon', 'priority' => 'A', 'plan_start_date' => '2026-11-30']);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);
        $day = ['available' => true, 'duration_min' => 60];
        RunnerProfile::create(['user_id' => $user->id, 'threshold_speed' => 4.3, 'weekly_availability' => [
            'monday' => $day, 'tuesday' => $day + ['fixed' => ['type' => 'interval', 'label' => 'KRAFT Laufclub']],
            'wednesday' => $day, 'thursday' => $day, 'friday' => $day, 'saturday' => $day, 'sunday' => $day,
        ]]);
        $club = TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id, 'description' => '',
            'planned_date' => '2026-12-01', 'type' => 'interval', 'title' => 'KRAFT Laufclub', 'intensity' => 'high',
            'status' => 'planned', 'sort_order' => 1, 'duration_min' => 60, 'distance_km' => 10.7,
        ]);

        return [$user->fresh(), $plan, $club];
    }

    private function ask(User $user, string $text): CoachMessage
    {
        $q = CoachMessage::create(['user_id' => $user->id, 'role' => 'user', 'content' => $text, 'channel' => 'telegram']);
        (new CoachReplyJob($q->id))->handle(app(CoachChatService::class));

        return CoachMessage::where('reply_to_id', $q->id)->firstOrFail();
    }

    private function toolResult(int $request): array
    {
        $tool = collect($this->requests[$request]['messages'])->where('role', 'tool')->last();

        return json_decode($tool['content'], true) ?? ['raw' => $tool['content']];
    }

    public function test_get_plan_and_context_show_the_appointment_of_today(): void
    {
        [$user, , $club] = $this->jan();
        $this->script = [[['get_plan', ['from' => self::TODAY, 'to' => '2026-12-02']]], 'Steht.'];

        $this->ask($user, 'Was steht heute an?');

        $system = $this->requests[0]['messages'][0]['content'];
        $this->assertStringContainsString('feste Termin „KRAFT Laufclub“', $system);
        $this->assertStringContainsString('op cancel', $system);

        $result = $this->toolResult(1);
        $this->assertSame('appointment', $result['without_id'][0]['kind']);
        $this->assertSame(self::TODAY, $result['without_id'][0]['date']);
        $this->assertSame($club->id, $result['sessions'][0]['id']);
    }

    public function test_striking_another_day_when_today_is_meant_is_sent_back_once(): void
    {
        [$user, $plan, $club] = $this->jan();
        $this->script = [
            [['plan_change', ['mode' => 'apply', 'steps' => [['op' => 'remove', 'session_id' => $club->id]]]]],
            [['plan_change', ['mode' => 'apply', 'steps' => [
                ['op' => 'cancel', 'date' => self::TODAY],
                ['op' => 'add', 'date' => self::TODAY, 'sport' => 'swim', 'duration_min' => 45],
            ]]]],
            'Laufclub heute abgesagt, Schwimmen steht.',
        ];

        $reply = $this->ask($user, 'Ich gehe heute nicht zum Laufclub ich gehe als Alternative schwimmen.');

        $this->assertStringContainsString('spricht von heute', $this->toolResult(1)['rejected'][0]);
        $this->assertSame('planned', $club->fresh()->status, 'der Laufclub am 01.12. bleibt');

        $swim = TrainingSession::whereDate('planned_date', self::TODAY)->firstOrFail();
        $this->assertSame('cross_training', $swim->type);
        $this->assertSame('Swim', $swim->sport_type);
        $this->assertSame(45, (int) $swim->duration_min);
        $this->assertNull($swim->distance_km);

        $this->assertTrue($plan->fresh()->availability_overrides[self::TODAY]['fixed_off']);
        $this->assertNull(FixedAppointment::forUser($user->fresh(), CarbonImmutable::parse(self::TODAY)));
        $this->assertSame([], FixedAppointment::unplanned($user->fresh(), CarbonImmutable::today(), CarbonImmutable::today()));

        $labels = collect($reply->actions)->pluck('label')->implode(' | ');
        $this->assertStringContainsString('Abgesagt (Dienstag, 24.11.): KRAFT Laufclub', $labels);
        $this->assertStringContainsString('Schwimmen locker', $labels);

        // Rückgängig bringt den Termin zurück und nimmt das Schwimmen weg.
        $this->assertTrue(CoachChange::first()->undo());
        $this->assertNull(TrainingSession::find($swim->id));
        $this->assertNotNull(FixedAppointment::forUser($user->fresh(), CarbonImmutable::parse(self::TODAY)));
    }

    public function test_cancel_takes_a_scheduled_session_of_the_appointment_with_it(): void
    {
        [$user, $plan] = $this->jan();
        $scheduled = TrainingSession::create([
            'user_id' => $user->id, 'description' => '', 'planned_date' => self::TODAY, 'type' => 'interval',
            'title' => 'KRAFT Laufclub', 'intensity' => 'high', 'status' => 'planned', 'sort_order' => 1, 'duration_min' => 50,
        ]);
        $this->script = [[['plan_change', ['mode' => 'apply', 'steps' => [['op' => 'cancel', 'date' => self::TODAY]]]]], 'Abgesagt.'];

        $this->ask($user, 'Sag den Laufclub heute ab');

        $this->assertNull(TrainingSession::find($scheduled->id));
    }

    public function test_a_day_without_appointment_cannot_be_cancelled(): void
    {
        [$user] = $this->jan();
        $this->script = [[['plan_change', ['mode' => 'apply', 'steps' => [['op' => 'cancel', 'date' => '2026-11-25']]]]], 'Hm.'];

        $this->ask($user, 'Mittwoch absagen');

        $this->assertStringContainsString('kein fester Termin', $this->toolResult(1)['rejected'][0]);
    }

    public function test_the_briefing_shows_what_today_shows(): void
    {
        [$user] = $this->jan();
        $briefing = app(TelegramBriefing::class);

        $this->assertStringContainsString('Heute: KRAFT Laufclub', $briefing->today($user));

        $this->script = [[['plan_change', ['mode' => 'apply', 'steps' => [
            ['op' => 'cancel', 'date' => self::TODAY],
            ['op' => 'add', 'date' => self::TODAY, 'sport' => 'swim'],
        ]]]], 'Erledigt.'];
        $this->ask($user, 'Heute kein Laufclub, ich gehe schwimmen');

        $text = $briefing->today($user->fresh());
        $this->assertStringContainsString('Schwimmen locker', $text);
        $this->assertStringNotContainsString('nichts im Plan', $text);
    }

    public function test_the_briefing_shows_the_stored_daily_suggestion(): void
    {
        [$user] = $this->jan();
        Carbon::setTestNow('2026-11-25 08:00:00'); // Mittwoch, kein Termin
        $user->runnerProfile->update(['recommendation_date' => '2026-11-25', 'today_recommendation' => json_encode(['type' => 'easy_run', 'title' => 'Lockerer Lauf', 'duration_min' => 40])]);

        $this->assertStringContainsString('Vorschlag für heute: Lockerer Lauf', app(TelegramBriefing::class)->today($user->fresh()));
    }
}
