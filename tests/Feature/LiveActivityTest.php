<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\LiveActivity;
use App\Models\LiveActivityDevice;
use App\Models\RunnerProfile;
use App\Services\AI\CoachingTextService;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\LiveActivity\LiveActivityService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Live Activity „Heute steht an“ (Mockup „Zone3 Live Activities“, Jan am
 * 12.11.2026): kein Countdown, Start 6 h vor der üblichen Laufzeit, an
 * jedem Tag mit Einheit außer Ruhetagen, nach dem Import „Erledigt“.
 */
class LiveActivityTest extends TestCase
{
    use RefreshDatabase;

    /** Ein Donnerstag. */
    private const TODAY = '2026-11-12';

    private const START_TOKEN = 'aa11bb22cc33dd44ee55ff6600112233';
    private const UPDATE_TOKEN = 'ff00ee11dd22cc33bb44aa5599887766';

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('07:00');

        config([
            'services.apns.key_id'  => 'KEY1234567',
            'services.apns.team_id' => 'TEAM123456',
            'services.apns.key'     => $this->p8(),
        ]);
        Http::fake(fn (Request $r) => str_contains($r->url(), '/3/device/' . $this->goneToken)
            ? Http::response(['reason' => 'Unregistered'], 410)
            : Http::response('', 200));
    }

    /** Ein Token, das APNs als abgemeldet beantwortet. */
    private string $goneToken = 'none';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $time, string $day = self::TODAY): void
    {
        Carbon::setTestNow("{$day} {$time}:00");
        CarbonImmutable::setTestNow("{$day} {$time}:00");
    }

    private function p8(): string
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    /** @return array{0: User, 1: TrainingPlan} */
    private function athlete(): array
    {
        $user  = User::factory()->onboarded()->create(['widget_token' => Str::random(48)]);
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM Hannover', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 25,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        return [$user, $plan];
    }

    private function plannedSession(TrainingPlan $plan, array $attrs = []): TrainingSession
    {
        return TrainingSession::create($attrs + [
            'user_id' => $plan->user_id, 'training_plan_id' => $plan->id, 'event_id' => $plan->event_id,
            'planned_date' => self::TODAY, 'type' => 'tempo_run', 'title' => 'Schwelle 3×10 min',
            'distance_km' => 10.5, 'duration_min' => 52, 'pace_target' => '4:20 min/km', 'zone' => 4,
            'intensity' => 'high', 'status' => 'planned',
        ]);
    }

    /** Läufe an Donnerstagen um 18:30 Berliner Zeit (17:30 UTC im Winter). */
    private function eveningRuns(User $user, int $weeks = 3): void
    {
        for ($w = 1; $w <= $weeks; $w++) {
            Activity::create([
                'user_id' => $user->id, 'strava_id' => 1000 + $w, 'name' => 'Abendlauf', 'type' => 'Run',
                'start_date' => CarbonImmutable::parse(self::TODAY)->subWeeks($w)->format('Y-m-d') . ' 17:30:00',
                'distance' => 10000, 'moving_time' => 2700, 'elapsed_time' => 2750, 'average_speed' => 10000 / 2700,
            ]);
        }
    }

    private function register(User $user): void
    {
        $this->postJson("/widget/{$user->widget_token}/live-activity", ['kind' => 'start', 'token' => self::START_TOKEN])->assertOk();
    }

    private function livePushes(): array
    {
        return collect(Http::recorded())
            ->map(fn ($r) => $r[0])
            ->filter(fn (Request $r) => $r->hasHeader('apns-push-type', 'liveactivity'))
            ->values()
            ->all();
    }

    public function test_the_app_registers_tokens_with_the_widget_token_and_without_a_session(): void
    {
        [$user] = $this->athlete();

        $this->register($user);
        $this->postJson("/widget/{$user->widget_token}/live-activity", [
            'kind' => 'update', 'token' => self::UPDATE_TOKEN, 'activity_id' => 'A-1', 'day' => self::TODAY,
        ])->assertOk();

        $this->assertSame($user->id, LiveActivityDevice::firstWhere('start_token', self::START_TOKEN)->user_id);
        $this->assertSame(self::UPDATE_TOKEN, LiveActivity::firstWhere('activity_id', 'A-1')->push_token);

        $this->postJson('/widget/' . Str::random(48) . '/live-activity', ['kind' => 'start', 'token' => self::START_TOKEN])->assertNotFound();
        $this->postJson("/widget/{$user->widget_token}/live-activity", ['kind' => 'start', 'token' => 'kein hex'])->assertUnprocessable();
    }

    public function test_it_starts_six_hours_before_the_usual_run_and_not_earlier(): void
    {
        [$user, $plan] = $this->athlete();
        $this->plannedSession($plan);
        $this->eveningRuns($user);
        $this->register($user);

        $service = app(LiveActivityService::class);
        $this->assertSame(18 * 60 + 30, $service->usualMinute($user, CarbonImmutable::today()));
        $this->assertSame('12:30', $service->startAt($user, CarbonImmutable::today())->format('H:i'));

        $this->at('12:29');
        $service->tick();
        $this->assertCount(0, $this->livePushes());

        $this->at('12:30');
        $service->tick();
        $pushes = $this->livePushes();
        $this->assertCount(1, $pushes);
        $this->assertStringEndsWith('/3/device/' . self::START_TOKEN, $pushes[0]->url());
        $this->assertTrue($pushes[0]->hasHeader('apns-topic', 'run.zone3.app.push-type.liveactivity'));

        $aps = $pushes[0]->data()['aps'];
        $this->assertSame('start', $aps['event']);
        $this->assertSame('TrainingActivityAttributes', $aps['attributes-type']);
        $this->assertSame(['day' => self::TODAY], $aps['attributes']);
        $this->assertSame('Tempolauf', $aps['content-state']['name']);
        $this->assertSame('Tempo', $aps['content-state']['label']);
        $this->assertFalse($aps['content-state']['done']);
        $this->assertSame([['v' => '10,5', 'u' => 'km'], ['v' => '52', 'u' => 'min'], ['v' => '4:20', 'u' => '/km']], $aps['content-state']['metrics']);
        $this->assertSame('Tagesform noch offen · Antippen zum Eintragen', $aps['content-state']['foot']);
        $this->assertStringEndsWith('/dashboard?tagesform=1', $aps['content-state']['url']);

        // Einmal am Tag.
        $this->at('12:31');
        $service->tick();
        $this->assertCount(1, $this->livePushes());
    }

    public function test_without_history_it_starts_at_seven_and_never_before_five(): void
    {
        [$user] = $this->athlete();
        $service = app(LiveActivityService::class);

        $this->assertSame('07:00', $service->startAt($user, CarbonImmutable::today())->format('H:i'));

        // Läufe um 07:00 → 01:00 wäre zu früh.
        for ($w = 1; $w <= 2; $w++) {
            Activity::create([
                'user_id' => $user->id, 'strava_id' => 2000 + $w, 'name' => 'Morgenlauf', 'type' => 'Run',
                'start_date' => CarbonImmutable::parse(self::TODAY)->subWeeks($w)->format('Y-m-d') . ' 06:00:00',
                'distance' => 8000, 'moving_time' => 2400, 'elapsed_time' => 2400, 'average_speed' => 8000 / 2400,
            ]);
        }
        $this->assertSame('05:00', $service->startAt($user, CarbonImmutable::today())->format('H:i'));
    }

    public function test_rest_days_and_days_without_sessions_start_nothing_but_strength_does(): void
    {
        [$user, $plan] = $this->athlete();
        $this->register($user);
        $service = app(LiveActivityService::class);

        $rest = $this->plannedSession($plan, ['type' => 'rest', 'title' => 'Ruhetag', 'distance_km' => null, 'duration_min' => null, 'pace_target' => null, 'zone' => null]);
        $service->tick();
        $this->assertCount(0, $this->livePushes());

        $rest->delete();
        $this->plannedSession($plan, ['type' => 'strength', 'title' => 'Beine und Rumpf', 'distance_km' => null, 'duration_min' => 30, 'pace_target' => null, 'zone' => null]);
        $service->tick();

        $state = $this->livePushes()[0]->data()['aps']['content-state'];
        $this->assertSame('Krafttraining', $state['name']);
        $this->assertSame([['v' => '30', 'u' => 'min']], $state['metrics']);
    }

    public function test_before_the_plan_starts_the_server_writes_the_daily_suggestion_once(): void
    {
        [$user, $plan] = $this->athlete();
        $plan->event->update(['plan_start_date' => '2026-11-30']);
        RunnerProfile::create(['user_id' => $user->id]);
        $this->register($user);

        $text = $this->mock(CoachingTextService::class);
        $text->shouldReceive('withCoach')->andReturnSelf();
        $text->shouldReceive('forUser')->andReturnSelf();
        $text->shouldReceive('generateTodayRecommendation')->once()->andReturn([
            'type' => 'easy_run', 'title' => 'Locker halten', 'description' => 'Form halten bis zum Planstart.',
            'distance_km' => 8, 'duration_min' => 45, 'pace_target' => null, 'zone' => 2, 'intensity' => 'low',
        ]);

        $service = app(LiveActivityService::class);
        $service->tick();
        $service->tick();

        $pushes = $this->livePushes();
        $this->assertCount(1, $pushes);
        $state = $pushes[0]->data()['aps']['content-state'];
        $this->assertSame('Locker halten', $state['title']);
        // Ohne Tagesform zeigt sie zuerst den Hinweis darauf.
        $this->assertSame('Tagesform noch offen · Antippen zum Eintragen', $state['foot']);
        $this->assertFalse($state['done']);
    }

    public function test_with_a_running_plan_and_nothing_today_no_suggestion_is_written(): void
    {
        [$user] = $this->athlete();
        RunnerProfile::create(['user_id' => $user->id]);
        $this->register($user);

        $this->mock(CoachingTextService::class)->shouldNotReceive('generateTodayRecommendation');

        app(LiveActivityService::class)->tick();
        $this->assertCount(0, $this->livePushes());
    }

    public function test_after_the_import_it_shows_done_with_the_real_numbers_and_ends(): void
    {
        [$user, $plan] = $this->athlete();
        $session = $this->plannedSession($plan);
        WellbeingEntry::create(['user_id' => $user->id, 'date' => self::TODAY, 'energy_level' => 7, 'mood' => 7, 'sleep_quality' => 7, 'muscle_soreness' => 3, 'stress_level' => 3]);
        LiveActivity::create(['user_id' => $user->id, 'activity_id' => 'A-1', 'day' => self::TODAY, 'push_token' => self::UPDATE_TOKEN]);
        $service = app(LiveActivityService::class);

        // Erster Lauf: der aktuelle Stand geht einmal raus, danach nichts mehr.
        $service->tick();
        $service->tick();
        $pushes = $this->livePushes();
        $this->assertCount(1, $pushes);
        $this->assertSame('update', $pushes[0]->data()['aps']['event']);
        $this->assertNull($pushes[0]->data()['aps']['content-state']['foot']);

        $this->at('18:47');
        $activity = Activity::create([
            'user_id' => $user->id, 'strava_id' => 99, 'name' => 'Schwelle', 'type' => 'Run',
            'start_date' => self::TODAY . ' 17:00:00', 'distance' => 10700, 'moving_time' => 2782,
            'elapsed_time' => 2800, 'average_speed' => 10700 / 2782,
        ]);
        $session->update(['status' => 'completed', 'activity_id' => $activity->id]);

        $service->tick();
        $aps = $this->livePushes()[1]->data()['aps'];
        $this->assertSame('end', $aps['event']);
        $this->assertTrue($aps['content-state']['done']);
        $this->assertSame([['v' => '10,7', 'u' => 'km'], ['v' => '46:22', 'u' => 'Zeit'], ['v' => '4:20', 'u' => '/km']], $aps['content-state']['metrics']);
        $this->assertStringEndsWith("/activities/{$activity->id}", $aps['content-state']['url']);
        $this->assertSame(CarbonImmutable::now()->addHours(3)->timestamp, $aps['dismissal-date']);
        $this->assertNotNull(LiveActivity::first()->ended_at);

        $service->tick();
        $this->assertCount(2, $this->livePushes());
    }

    public function test_yesterdays_activity_is_ended_and_gone_tokens_are_dropped(): void
    {
        [$user, $plan] = $this->athlete();
        $this->plannedSession($plan);
        LiveActivity::create(['user_id' => $user->id, 'activity_id' => 'A-0', 'day' => '2026-11-11', 'push_token' => self::UPDATE_TOKEN]);
        $this->register($user);

        $this->goneToken = self::START_TOKEN;

        app(LiveActivityService::class)->tick();

        $this->assertNotNull(LiveActivity::firstWhere('activity_id', 'A-0')->ended_at);
        $this->assertSame(0, LiveActivityDevice::count());
    }
}
