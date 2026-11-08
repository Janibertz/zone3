<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GarminConnection;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Watch\WorkoutKitPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Einheiten per WorkoutKit auf die Apple Watch (08.11.2026): der Server baut
 * das JSON, das Plugin in der iOS-App plant es nur ein.
 */
class AppleWatchWorkoutKitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 08:00:00'); // Montag

        $this->user = User::factory()->onboarded()->create();
        $event = Event::create([
            'user_id' => $this->user->id, 'name' => 'HM', 'event_date' => '2027-03-14',
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
        $this->plan = TrainingPlan::create(['user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plannedSession(array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'user_id' => $this->user->id, 'training_plan_id' => $this->plan->id, 'event_id' => $this->plan->event_id,
            'planned_date' => '2026-11-10', 'type' => 'interval', 'title' => '5×1000 m',
            'description' => 'Hart', 'intensity' => 'high', 'status' => 'planned',
            'steps' => [
                ['type' => 'warmup',   'duration_min' => 15, 'pace_target' => '5:40', 'repetitions' => null],
                ['type' => 'work',     'distance_m' => 1000, 'duration_min' => 3.8, 'pace_target' => '3:50', 'repetitions' => 5],
                ['type' => 'rest',     'distance_m' => 400, 'duration_min' => 2.5, 'pace_target' => null, 'repetitions' => 5],
                ['type' => 'cooldown', 'duration_min' => 10, 'pace_target' => '5:50', 'repetitions' => null],
            ],
        ]);
    }

    public function test_an_interval_session_becomes_warmup_block_and_cooldown(): void
    {
        $w = app(WorkoutKitPayload::class)->forSession($this->plannedSession());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $w['id']);
        $this->assertSame('2026-11-10', $w['date']);
        $this->assertSame('running', $w['activity']);

        // Ein- und Auslaufen nach Zeit, ohne Pace-Alarm.
        $this->assertSame(['distance_m' => null, 'seconds' => 900, 'speed' => null], $w['warmup']);
        $this->assertSame(600, $w['cooldown']['seconds']);
        $this->assertNull($w['cooldown']['speed']);

        // 5 × (1000 m Belastung mit ±5 % um 3:50 / 400 m Trabpause ohne Alarm).
        $this->assertCount(1, $w['blocks']);
        $block = $w['blocks'][0];
        $this->assertSame(5, $block['iterations']);
        $this->assertSame('work', $block['steps'][0]['purpose']);
        $this->assertSame(1000, $block['steps'][0]['distance_m']);
        $this->assertNull($block['steps'][0]['seconds']);
        $this->assertEqualsWithDelta(1000 / 230 * 0.95, $block['steps'][0]['speed'][0], 0.001);
        $this->assertEqualsWithDelta(1000 / 230 * 1.05, $block['steps'][0]['speed'][1], 0.001);
        $this->assertSame(['purpose' => 'recovery', 'distance_m' => 400, 'seconds' => null, 'speed' => null], $block['steps'][1]);
    }

    public function test_the_id_changes_only_when_the_session_changes(): void
    {
        $session = $this->plannedSession();
        $payload = app(WorkoutKitPayload::class);

        $first = $payload->forSession($session)['id'];
        $this->assertSame($first, $payload->forSession($session->fresh())['id']);

        $session->update(['planned_date' => '2026-11-11']);
        $this->assertNotSame($first, $payload->forSession($session->fresh())['id']);
    }

    public function test_upcoming_holds_the_next_seven_days_of_runs_only(): void
    {
        $this->plannedSession();                                                         // drin
        $this->plannedSession(['planned_date' => '2026-11-15', 'type' => 'easy_run', 'title' => 'Locker', 'steps' => null, 'duration_min' => 40]);
        $this->plannedSession(['planned_date' => '2026-11-16', 'title' => 'Zu weit']);    // 8. Tag
        $this->plannedSession(['planned_date' => '2026-11-08', 'title' => 'Gestern']);
        $this->plannedSession(['planned_date' => '2026-11-12', 'type' => 'race_prep', 'title' => 'Rennen', 'steps' => null]);
        $this->plannedSession(['planned_date' => '2026-11-13', 'type' => 'cross_training', 'sport_type' => 'VirtualRide', 'title' => 'Zwift', 'steps' => null]);
        $this->plannedSession(['planned_date' => '2026-11-14', 'status' => 'completed', 'title' => 'Erledigt']);

        $res = $this->actingAs($this->user)->getJson(route('apple-watch.workouts'))->assertOk();

        $this->assertTrue($res->json('enabled'));
        $this->assertSame(['5×1000 m', 'Locker'], collect($res->json('workouts'))->pluck('name')->all());

        // Ohne Abschnitte: die Einheit als ein Block nach Zeit.
        $easy = $res->json('workouts.1');
        $this->assertSame(1, $easy['blocks'][0]['iterations']);
        $this->assertSame(2400, $easy['blocks'][0]['steps'][0]['seconds']);
    }

    public function test_switched_off_means_nothing_to_schedule(): void
    {
        $this->plannedSession();

        $this->actingAs($this->user)->patch(route('apple-watch.sync'), ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->user->fresh()->apple_watch_sync);

        $this->actingAs($this->user->fresh())->getJson(route('apple-watch.workouts'))
            ->assertJson(['enabled' => false, 'workouts' => []]);
    }

    public function test_garmin_users_get_no_watch_workouts(): void
    {
        $this->plannedSession();
        $this->actingAs($this->user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.appleWatch.sync', true));

        GarminConnection::create([
            'user_id' => $this->user->id, 'garmin_user_id' => 'g-1', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'access_token_expires_at' => now()->addHours(20), 'permissions' => [GarminConnection::WORKOUTS],
            'ai_consent_at' => now(),
        ]);
        $user = $this->user->fresh();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.appleWatch', null));
        $this->actingAs($user)->getJson(route('apple-watch.workouts'))->assertJson(['enabled' => false]);
    }

    public function test_a_single_session_for_the_button(): void
    {
        $session = $this->plannedSession();

        $this->actingAs($this->user)->getJson(route('apple-watch.workout', $session))
            ->assertOk()
            ->assertJsonPath('workout.session', $session->id);

        $this->actingAs(User::factory()->onboarded()->create())
            ->getJson(route('apple-watch.workout', $session))
            ->assertForbidden();
    }
}
