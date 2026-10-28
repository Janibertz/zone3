<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GarminConnection;
use App\Models\GarminScheduledWorkout;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Workout;
use App\Services\Garmin\GarminWorkoutBuilder;
use App\Services\Garmin\GarminWorkoutSync;
use App\Services\WorkoutPaceResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ein Builder-Workout einplanen (Jan, 28.10.2026): Zone3 ist die Wahrheit,
 * die Uhr folgt. Die Einheit kommt zusaetzlich zum Plan, auch ohne Plan.
 */
class WorkoutScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-10-28 08:00:00';

    private User $user;
    private int $nextId = 500;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY);
        config(['services.garmin.client_id' => 'c', 'services.garmin.client_secret' => 's']);
        Queue::fake();

        $this->user = User::factory()->onboarded()->create();
        RunnerProfile::create(['user_id' => $this->user->id, 'threshold_speed' => 4.25]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'DELETE') {
                return Http::response(null, 204);
            }
            if (str_contains($request->url(), '/workoutportal/workout/v2')) {
                return Http::response(['workoutId' => $this->nextId++]);
            }
            if (str_contains($request->url(), '/training-api/schedule')) {
                return Http::response(['scheduleId' => $this->nextId++]);
            }

            return Http::response(null, 404);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Jans Test vom 28.10.: 10 min ein, 10 × (30 s Zone 5 + 2 min Zone 2), 10 min aus. */
    private function intervals(): Workout
    {
        return Workout::create([
            'user_id' => $this->user->id, 'name' => '10×30 s', 'type' => 'interval',
            'blocks'  => [
                ['type' => 'warmup', 'duration_mode' => 'time', 'duration_sec' => 600, 'pace_zone' => 2],
                ['type' => 'repeat', 'repetitions' => 10, 'steps' => [
                    ['type' => 'work', 'duration_mode' => 'time', 'duration_sec' => 30, 'pace_zone' => 5],
                    ['type' => 'rest', 'duration_mode' => 'time', 'duration_sec' => 120, 'pace_zone' => 2],
                ]],
                ['type' => 'cooldown', 'duration_mode' => 'time', 'duration_sec' => 600, 'pace_zone' => 2],
            ],
        ]);
    }

    private function connectGarmin(): void
    {
        GarminConnection::create([
            'user_id' => $this->user->id, 'garmin_user_id' => 'g-1', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'access_token_expires_at' => now()->addHours(20), 'permissions' => [GarminConnection::WORKOUTS],
            'ai_consent_at' => now(),
        ]);
    }

    // ── 30 Sekunden bleiben 30 Sekunden ──────────────────────────────────

    public function test_a_thirty_second_interval_stays_thirty_seconds(): void
    {
        $resolved = app(WorkoutPaceResolver::class)->resolve($this->intervals(), $this->user->runnerProfile);
        $work     = collect($resolved['steps'])->firstWhere('type', 'work');

        $this->assertEqualsWithDelta(0.5, $work['duration_min'], 0.0001);
        // 10 + 10 × 2,5 + 10 = 45 Minuten, nicht 50.
        $this->assertSame(45, $resolved['duration_min']);

        $body   = app(GarminWorkoutBuilder::class)->build('x', null, $resolved['steps']);
        $repeat = $body['segments'][0]['steps'][1];
        $this->assertSame('WorkoutRepeatStep', $repeat['type']);
        $this->assertSame(10, $repeat['repeatValue']);
        $this->assertSame(30.0, $repeat['steps'][0]['durationValue']);
        $this->assertSame(120.0, $repeat['steps'][1]['durationValue']);
    }

    // ── Einplanen ────────────────────────────────────────────────────────

    public function test_scheduling_adds_a_session_next_to_the_planned_run(): void
    {
        $event = Event::create([
            'user_id' => $this->user->id, 'name' => 'HM', 'event_date' => '2026-12-06',
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ]);
        $plan = TrainingPlan::create(['user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);
        $planned = TrainingSession::create([
            'user_id' => $this->user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => '2026-10-29', 'type' => 'easy_run', 'title' => 'Locker', 'intensity' => 'low', 'status' => 'planned', 'sort_order' => 0,
        ]);

        $this->actingAs($this->user)
            ->postJson(route('workouts.schedule', $this->intervals()), ['date' => '2026-10-29'])
            ->assertOk()
            ->assertJson(['success' => true, 'garmin' => false]);

        // Der geplante Lauf bleibt — der Athlet darf bewusst zweimal laufen.
        $this->assertNotNull($planned->fresh());

        $added = TrainingSession::where('title', '10×30 s')->firstOrFail();
        $this->assertNull($added->training_plan_id);
        $this->assertNull($added->event_id);
        $this->assertNotNull($added->pinned_at);
        $this->assertSame('interval', $added->type);
        $this->assertSame('planned', $added->status);
        $this->assertSame(1, $added->sort_order);
        $this->assertCount(4, $added->steps);
    }

    public function test_scheduling_works_without_a_plan(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('workouts.schedule', $this->intervals()), ['date' => '2026-10-30'])
            ->assertOk();

        $this->assertSame(1, TrainingSession::where('user_id', $this->user->id)->whereDate('planned_date', '2026-10-30')->count());
    }

    public function test_a_past_date_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('workouts.schedule', $this->intervals()), ['date' => '2026-10-27'])
            ->assertStatus(422);
    }

    public function test_a_foreign_private_workout_cannot_be_scheduled(): void
    {
        $other = User::factory()->onboarded()->create();

        $this->actingAs($other)
            ->postJson(route('workouts.schedule', $this->intervals()), ['date' => '2026-10-30'])
            ->assertForbidden();
    }

    // ── Garmin folgt ─────────────────────────────────────────────────────

    public function test_garmin_follows_a_scheduled_workout_without_a_plan(): void
    {
        $this->connectGarmin();

        $this->actingAs($this->user)
            ->postJson(route('workouts.schedule', $this->intervals()), ['date' => '2026-10-29'])
            ->assertOk()
            ->assertJson(['garmin' => true]);

        $sync = app(GarminWorkoutSync::class);
        $this->assertSame(1, $sync->sync($this->user->fresh())['created']);

        $session = TrainingSession::where('title', '10×30 s')->firstOrFail();
        $this->assertSame($session->id, GarminScheduledWorkout::sole()->training_session_id);

        // Verschoben: neuer Termin bei Garmin.
        $session->update(['planned_date' => '2026-10-31']);
        $this->assertSame(['created' => 1, 'removed' => 1, 'kept' => 0], $sync->sync($this->user->fresh()));
        $this->assertSame('2026-10-31', GarminScheduledWorkout::sole()->date->toDateString());

        // Geloescht: weg von der Uhr.
        $session->delete();
        $this->assertSame(['created' => 0, 'removed' => 1, 'kept' => 0], $sync->sync($this->user->fresh()));
        $this->assertSame(0, GarminScheduledWorkout::count());
    }

    public function test_accepting_todays_recommendation_does_not_overwrite_a_scheduled_workout(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('workouts.schedule', $this->intervals()), ['date' => '2026-10-28'])
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson(route('ai.recommendation.accept'), ['type' => 'easy_run', 'title' => 'Locker 40', 'intensity' => 'low'])
            ->assertOk();

        $titles = TrainingSession::where('user_id', $this->user->id)->whereDate('planned_date', '2026-10-28')->pluck('title')->sort()->values()->all();
        $this->assertSame(['10×30 s', 'Locker 40'], $titles);
    }
}
