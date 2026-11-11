<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Widget\WidgetPayload;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * iOS-Widget (Mockup „Zone3 Widget“, Jan am 11.11.2026): heute, „Als
 * Nächstes“ mit Kraft und Rad, Rennleiste, Wochen-Kilometer — hinter einem
 * Token, weil das Widget keine Session hat.
 */
class WidgetPayloadTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-11';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY . ' 07:00:00');
        CarbonImmutable::setTestNow(self::TODAY . ' 07:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: TrainingPlan, 2: Event} */
    private function athlete(array $user = []): array
    {
        $user  = User::factory()->onboarded()->create($user);
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM Hannover', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 25,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        return [$user, $plan, $event];
    }

    private function plannedSession(TrainingPlan $plan, array $attrs = []): TrainingSession
    {
        return TrainingSession::create($attrs + [
            'user_id' => $plan->user_id, 'training_plan_id' => $plan->id, 'event_id' => $plan->event_id,
            'planned_date' => self::TODAY, 'type' => 'tempo_run', 'title' => 'Schwelle 3×10 min',
            'distance_km' => 11.4, 'duration_min' => 58, 'pace_target' => '4:12 min/km', 'zone' => 4,
            'intensity' => 'high', 'status' => 'planned',
            'steps' => [
                ['type' => 'warmup', 'duration_min' => 12, 'zone' => 1],
                ['type' => 'work', 'duration_min' => 10, 'repetitions' => 3, 'zone' => 4, 'pace_target' => '4:12'],
                ['type' => 'rest', 'duration_min' => 2, 'repetitions' => 2, 'zone' => 1],
                ['type' => 'cooldown', 'duration_min' => 10, 'zone' => 1],
            ],
        ]);
    }

    public function test_the_app_gets_one_token_and_the_widget_reads_with_it(): void
    {
        [$user, $plan] = $this->athlete();
        $this->plannedSession($plan);

        $token = $this->actingAs($user)->postJson(route('widget.token'))->assertOk()->json('token');
        $this->assertSame($token, $this->actingAs($user)->postJson(route('widget.token'))->json('token'));
        $this->assertSame(48, strlen($token));

        auth()->logout();
        $this->getJson(route('widget.show', $token))
            ->assertOk()
            ->assertJsonPath('days.0.date', self::TODAY)
            ->assertJsonPath('days.0.items.0.title', 'Schwelle 3×10 min')
            ->assertJsonPath('race.name', 'HM Hannover');

        $this->getJson('/widget/' . Str::random(48) . '.json')->assertNotFound();
        $this->assertArrayNotHasKey('widget_token', $user->fresh()->toArray());
    }

    public function test_today_carries_chip_numbers_and_band(): void
    {
        [$user, $plan] = $this->athlete();
        $this->plannedSession($plan);

        $item = app(WidgetPayload::class)->build($user)['days'][0]['items'][0];

        $this->assertSame('Tempo', $item['label']);
        $this->assertSame('warn', $item['accent']);
        $this->assertSame('street', $item['photo']);
        $this->assertSame('4', $item['zone']);
        $this->assertSame('4:12', $item['pace']);
        $this->assertSame(11.4, $item['km']);
        $this->assertSame(58, $item['min']);
        // Einlaufen, 3 × Belastung mit 2 Pausen dazwischen, Auslaufen.
        $this->assertCount(7, $item['band']);
        $this->assertTrue($item['band'][1]['w']);
        $this->assertSame(4, $item['band'][1]['z']);
    }

    public function test_next_up_holds_strength_and_bike_and_no_skipped_sessions(): void
    {
        [$user, $plan] = $this->athlete();
        $this->plannedSession($plan);
        $this->plannedSession($plan, ['planned_date' => '2026-11-13', 'type' => 'strength', 'title' => 'Beine und Rumpf', 'distance_km' => null, 'duration_min' => 30, 'steps' => null, 'pace_target' => null]);
        $this->plannedSession($plan, ['planned_date' => '2026-11-14', 'type' => 'cross_training', 'sport_type' => 'VirtualRide', 'title' => 'Lockeres Rad', 'distance_km' => 25, 'duration_min' => 60, 'steps' => null, 'pace_target' => null]);
        $this->plannedSession($plan, ['planned_date' => '2026-11-15', 'title' => 'Ausgelassen', 'status' => 'skipped']);

        $days = collect(app(WidgetPayload::class)->build($user)['days']);

        $this->assertSame(['2026-11-11', '2026-11-13', '2026-11-14'], $days->pluck('date')->all());
        $strength = $days[1]['items'][0];
        $this->assertSame('Kraft', $strength['label']);
        $this->assertNull($strength['km']);
        $this->assertFalse($strength['run']);
        $bike = $days[2]['items'][0];
        $this->assertSame('Rad', $bike['label']);
        $this->assertNull($bike['km'], 'Rad zeigt Minuten, keine Kilometer');
        $this->assertNull($bike['pace']);
    }

    public function test_a_completed_session_shows_what_was_run(): void
    {
        [$user, $plan] = $this->athlete();
        $activity = Activity::create([
            'user_id' => $user->id, 'strava_id' => 123, 'name' => 'Morgenlauf', 'type' => 'Run',
            'start_date' => self::TODAY . ' 06:00:00', 'distance' => 11600, 'moving_time' => 2891,
            'elapsed_time' => 2950, 'average_speed' => 11600 / 2891,
        ]);
        $this->plannedSession($plan, ['status' => 'completed', 'activity_id' => $activity->id]);

        $payload = app(WidgetPayload::class)->build($user);
        $item    = $payload['days'][0]['items'][0];

        $this->assertSame('completed', $item['status']);
        $this->assertSame(11.6, $item['actual']['km']);
        $this->assertSame('4:09', $item['actual']['pace']);
        $this->assertSame(11.6, $payload['week']['run_km']);
        $this->assertSame(11.4, $payload['week']['planned_km']);
    }

    public function test_before_the_plan_starts_the_saved_suggestion_comes_without_a_model_call(): void
    {
        [$user, $plan, $event] = $this->athlete();
        $event->update(['plan_start_date' => '2026-11-30']);
        $user->runnerProfile()->create([
            'recommendation_date'  => self::TODAY,
            'today_recommendation' => json_encode(['type' => 'easy_run', 'title' => 'Lockerer Kältestart', 'distance_km' => 6.4, 'duration_min' => 40]),
        ]);

        $payload = app(WidgetPayload::class)->build($user->fresh());

        $this->assertSame('2026-11-30', $payload['plan_start']);
        $this->assertSame('Lockerer Kältestart', $payload['suggestion']['title']);
        $this->assertSame('suggested', $payload['suggestion']['status']);
    }

    public function test_yesterdays_suggestion_is_not_shown(): void
    {
        [$user] = $this->athlete();
        $user->runnerProfile()->create([
            'recommendation_date'  => '2026-11-10',
            'today_recommendation' => json_encode(['type' => 'easy_run', 'title' => 'Gestern']),
        ]);

        $this->assertNull(app(WidgetPayload::class)->build($user->fresh())['suggestion']);
    }
}
