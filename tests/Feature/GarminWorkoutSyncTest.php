<?php

namespace Tests\Feature;

use App\Jobs\SyncGarminWorkoutsJob;
use App\Models\Event;
use App\Models\GarminConnection;
use App\Models\GarminScheduledWorkout;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Workout;
use App\Services\Garmin\GarminDisconnector;
use App\Services\Garmin\GarminWorkoutBuilder;
use App\Services\Garmin\GarminWorkoutSync;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Schritt 5: der Plan auf der Garmin-Uhr, ueber die Training API V2.
 *
 * Jans Regeln vom 28.10.2026: die naechsten 7 Tage, automatisch, fuer jeden
 * mit Garmin-Verbindung; Laeufe ja, Vereinstermine und Radeinheiten nicht.
 */
class GarminWorkoutSyncTest extends TestCase
{
    use RefreshDatabase;

    // Mittwoch. Der Vereinstermin liegt dienstags, also am 03.11. im Fenster.
    private const TODAY = '2026-10-28 08:00:00';

    private const API = 'https://apis.garmin.com';

    private User $user;
    private TrainingPlan $plan;
    private int $nextId = 1000;
    private int $garminStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY);
        config(['services.garmin.client_id' => 'c', 'services.garmin.client_secret' => 's']);
        Queue::fake();

        $this->user = User::factory()->onboarded()->create();

        GarminConnection::create([
            'user_id'                 => $this->user->id,
            'garmin_user_id'          => 'g-1',
            'access_token'            => 'tok',
            'refresh_token'           => 'ref',
            'access_token_expires_at' => now()->addHours(20),
            'permissions'             => [GarminConnection::WORKOUTS, GarminConnection::ACTIVITY],
            'ai_consent_at'           => now(),
        ]);

        RunnerProfile::create([
            'user_id'             => $this->user->id,
            'weekly_availability' => [
                'tuesday' => ['available' => true, 'duration_min' => 90, 'fixed' => ['type' => 'interval', 'label' => 'Laufclub']],
            ],
        ]);

        $event = Event::create([
            'user_id' => $this->user->id, 'name' => 'Halbmarathon', 'event_date' => '2026-12-06',
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ]);
        $this->plan = TrainingPlan::create([
            'user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true,
        ]);

        $this->fakeGarmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fakeGarmin(): void
    {
        Http::fake(function (Request $request) {
            if ($this->garminStatus !== 200) {
                return Http::response(['message' => 'nope'], $this->garminStatus);
            }
            if ($request->method() === 'DELETE') {
                return Http::response(null, 204);
            }
            if (str_contains($request->url(), '/workoutportal/workout/v2')) {
                return Http::response(['workoutId' => $this->nextId++]);
            }
            if (str_contains($request->url(), '/training-api/schedule')) {
                return Http::response(['scheduleId' => $this->nextId++, 'workoutId' => $request['workoutId'], 'date' => $request['date']]);
            }

            return Http::response(null, 404);
        });
    }

    private function plannedSession(array $attrs = []): TrainingSession
    {
        return TrainingSession::create($attrs + [
            'user_id' => $this->user->id, 'training_plan_id' => $this->plan->id, 'event_id' => $this->plan->event_id,
            'planned_date' => '2026-10-28', 'type' => 'easy_run', 'title' => 'Lockerer Lauf',
            'description' => 'Ruhig laufen.', 'distance_km' => 8, 'duration_min' => 48,
            'pace_target' => '5:50-6:10', 'zone' => 2, 'intensity' => 'low', 'status' => 'planned',
        ]);
    }

    private function sync(): array
    {
        return app(GarminWorkoutSync::class)->sync($this->user->fresh());
    }

    private function calls(string $method, string $path): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method && str_contains($r->url(), $path))->count();
    }

    // ── Workout-Format ───────────────────────────────────────────────────

    public function test_steps_become_a_training_api_workout_with_repeats(): void
    {
        $body = app(GarminWorkoutBuilder::class)->build('4×1000 m', 'Intervalle', [
            ['type' => 'warmup', 'label' => 'Einlaufen', 'duration_min' => 15],
            ['type' => 'work', 'label' => '1000 m', 'distance_m' => 1000, 'duration_min' => 4.2, 'pace_target' => '4:10-4:20', 'repetitions' => 4],
            ['type' => 'rest', 'label' => 'Trabpause', 'duration_min' => 2, 'repetitions' => 4],
            ['type' => 'cooldown', 'label' => 'Auslaufen', 'duration_min' => 10],
        ]);

        $this->assertSame('RUNNING', $body['sport']);
        $this->assertSame('Zone3', $body['workoutProvider']);
        $steps = $body['segments'][0]['steps'];
        $this->assertCount(3, $steps);

        $this->assertSame(['WorkoutStep', 1, 'WARMUP', 'TIME', 900.0, 'OPEN'], [
            $steps[0]['type'], $steps[0]['stepOrder'], $steps[0]['intensity'],
            $steps[0]['durationType'], $steps[0]['durationValue'], $steps[0]['targetType'],
        ]);

        $repeat = $steps[1];
        $this->assertSame('WorkoutRepeatStep', $repeat['type']);
        $this->assertSame(2, $repeat['stepOrder']);
        $this->assertSame('REPEAT_UNTIL_STEPS_CMPLT', $repeat['repeatType']);
        $this->assertSame(4, $repeat['repeatValue']);

        [$work, $rest] = $repeat['steps'];
        $this->assertSame([3, 'INTERVAL', 'DISTANCE', 1000.0, 'METER'], [
            $work['stepOrder'], $work['intensity'], $work['durationType'], $work['durationValue'], $work['durationValueType'],
        ]);
        // Langsame Grenze unten: 4:20 = 3,846 m/s, 4:10 = 4,0 m/s.
        $this->assertSame('PACE', $work['targetType']);
        $this->assertEqualsWithDelta(1000 / 260, $work['targetValueLow'], 0.0001);
        $this->assertEqualsWithDelta(1000 / 250, $work['targetValueHigh'], 0.0001);

        // Die Trabpause piept nicht.
        $this->assertSame([4, 'RECOVERY', 'OPEN'], [$rest['stepOrder'], $rest['intensity'], $rest['targetType']]);
        $this->assertSame([5, 'COOLDOWN'], [$steps[2]['stepOrder'], $steps[2]['intensity']]);
    }

    public function test_a_session_without_steps_is_one_step_with_a_pace_range(): void
    {
        $body  = app(GarminWorkoutBuilder::class)->forSession($this->plannedSession(['pace_target' => '6:00']));
        $step  = $body['segments'][0]['steps'][0];

        $this->assertSame(['ACTIVE', 'DISTANCE', 8000.0], [$step['intensity'], $step['durationType'], $step['durationValue']]);
        // Eine einzelne Pace: ±5 % Geschwindigkeit, wie bei der Apple Watch.
        $this->assertEqualsWithDelta(1000 / 360 * 0.95, $step['targetValueLow'], 0.0001);
        $this->assertEqualsWithDelta(1000 / 360 * 1.05, $step['targetValueHigh'], 0.0001);
    }

    // ── Abgleich ─────────────────────────────────────────────────────────

    public function test_the_next_seven_days_of_runs_go_to_the_calendar(): void
    {
        $today    = $this->plannedSession();
        $interval = $this->plannedSession(['planned_date' => '2026-10-30', 'type' => 'interval', 'title' => 'Intervalle']);
        $this->plannedSession(['planned_date' => '2026-10-29', 'type' => 'strength', 'title' => 'Kraft']);
        $this->plannedSession(['planned_date' => '2026-10-31', 'type' => 'cross_training', 'sport_type' => 'VirtualRide', 'title' => 'Zwift locker']);
        $this->plannedSession(['planned_date' => '2026-11-03', 'type' => 'interval', 'title' => 'Laufclub']);
        $this->plannedSession(['planned_date' => '2026-11-04', 'type' => 'race_prep', 'title' => 'Rennen']);
        $this->plannedSession(['planned_date' => '2026-11-05', 'title' => 'Hinter dem Fenster']);
        $this->plannedSession(['planned_date' => '2026-10-29', 'type' => 'rest', 'title' => 'Ruhetag']);

        $result = $this->sync();

        $this->assertSame(2, $result['created']);
        $this->assertEqualsCanonicalizing(
            [$today->id, $interval->id],
            GarminScheduledWorkout::pluck('training_session_id')->all(),
        );
        $this->assertSame(2, $this->calls('POST', '/workoutportal/workout/v2'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/training-api/schedule')
            && $r['date'] === '2026-10-30' && $r->hasHeader('Authorization', 'Bearer tok'));
    }

    public function test_an_unchanged_plan_costs_no_call(): void
    {
        $this->plannedSession();
        $this->sync();
        $before = Http::recorded()->count();

        $result = $this->sync();

        $this->assertSame(['created' => 0, 'removed' => 0, 'kept' => 1], $result);
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_a_changed_session_is_replaced(): void
    {
        $session = $this->plannedSession();
        $this->sync();
        $row = GarminScheduledWorkout::first();

        $session->update(['pace_target' => '5:30-5:45']);
        $result = $this->sync();

        $this->assertSame(1, $result['removed']);
        $this->assertSame(1, $result['created']);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), "/training-api/schedule/{$row->garmin_schedule_id}"));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), "/training-api/workout/v2/{$row->garmin_workout_id}"));
        $this->assertNotSame($row->garmin_workout_id, GarminScheduledWorkout::first()->garmin_workout_id);
    }

    public function test_a_moved_session_gets_the_new_date(): void
    {
        $session = $this->plannedSession();
        $this->sync();

        $session->update(['planned_date' => '2026-10-29']);
        $this->sync();

        $this->assertSame('2026-10-29', GarminScheduledWorkout::first()->date->toDateString());
    }

    public function test_a_deleted_or_skipped_session_leaves_the_calendar(): void
    {
        $deleted = $this->plannedSession();
        $skipped = $this->plannedSession(['planned_date' => '2026-10-29']);
        $this->sync();

        $deleted->delete();
        $skipped->update(['status' => 'skipped']);
        $result = $this->sync();

        $this->assertSame(2, $result['removed']);
        $this->assertSame(0, GarminScheduledWorkout::count());
    }

    public function test_a_completed_session_stays(): void
    {
        $session = $this->plannedSession();
        $this->sync();
        $before = Http::recorded()->count();

        $session->update(['status' => 'completed']);
        $this->sync();

        $this->assertSame(1, GarminScheduledWorkout::count());
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_switching_it_off_removes_what_zone3_scheduled(): void
    {
        $this->plannedSession();
        $this->sync();

        $this->user->update(['garmin_workout_sync' => false]);
        $result = $this->sync();

        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, GarminScheduledWorkout::count());
    }

    public function test_without_the_workout_permission_nothing_happens(): void
    {
        $this->user->garminConnection->update(['permissions' => [GarminConnection::ACTIVITY]]);
        $this->plannedSession();

        $this->sync();

        Http::assertNothingSent();
    }

    public function test_the_quota_stops_the_run_without_failing(): void
    {
        $this->plannedSession();
        $this->garminStatus = 429;

        $result = $this->sync();

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, GarminScheduledWorkout::count());
    }

    public function test_a_session_sent_by_button_is_kept_beyond_the_window(): void
    {
        $later = $this->plannedSession(['planned_date' => '2026-11-10']);

        app(GarminWorkoutSync::class)->push($later);
        $result = $this->sync();

        $this->assertSame(['created' => 0, 'removed' => 0, 'kept' => 1], $result);
        $this->assertTrue(GarminScheduledWorkout::first()->manual);
    }

    public function test_the_club_is_planned_on_an_override_day(): void
    {
        // Mit einer Tagesausnahme gilt der Vereinstermin an dem Tag nicht —
        // dann ist die Einheit eine normale und geht auf die Uhr.
        $this->plan->update(['availability_overrides' => ['2026-11-03' => ['available' => true, 'duration_min' => 60]]]);
        $this->plannedSession(['planned_date' => '2026-11-03', 'type' => 'interval', 'title' => 'Intervalle']);

        $this->assertSame(1, $this->sync()['created']);
    }

    // ── Wege hinein ──────────────────────────────────────────────────────

    public function test_the_button_sends_through_the_training_api(): void
    {
        $session = $this->plannedSession();

        $this->actingAs($this->user)
            ->postJson(route('training-sessions.send-to-garmin', $session))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, GarminScheduledWorkout::count());
    }

    public function test_the_button_refuses_a_bike_session(): void
    {
        $bike = $this->plannedSession(['type' => 'cross_training', 'sport_type' => 'VirtualRide']);

        $this->actingAs($this->user)
            ->postJson(route('training-sessions.send-to-garmin', $bike))
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_builder_workout_goes_to_the_chosen_day_and_is_left_alone(): void
    {
        $workout = Workout::create([
            'user_id' => $this->user->id, 'name' => '5×500 m', 'type' => 'interval',
            'blocks'  => [['type' => 'repeat', 'repetitions' => 5, 'steps' => [
                ['type' => 'work', 'distance_m' => 500, 'pace_zone' => 5],
                ['type' => 'rest', 'duration_min' => 1.5],
            ]]],
        ]);

        $this->actingAs($this->user)
            ->postJson(route('workouts.send-to-garmin', $workout), ['date' => '2026-10-31'])
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/training-api/schedule') && $r['date'] === '2026-10-31');

        // Der Abgleich fasst es nicht an.
        $this->assertSame(['created' => 0, 'removed' => 0, 'kept' => 0], $this->sync());
        $this->assertSame(1, GarminScheduledWorkout::whereNotNull('workout_id')->count());
    }

    public function test_changing_a_session_queues_a_sync(): void
    {
        $this->plannedSession();

        Queue::assertPushed(SyncGarminWorkoutsJob::class, fn ($job) => $job->userId === $this->user->id);
    }

    public function test_athletes_without_garmin_queue_nothing(): void
    {
        $this->user->garminConnection->delete();
        Queue::fake();

        $this->plannedSession();

        Queue::assertNotPushed(SyncGarminWorkoutsJob::class);
    }

    public function test_disconnecting_clears_upcoming_workouts_first(): void
    {
        $this->plannedSession();
        $this->sync();

        app(GarminDisconnector::class)->disconnect($this->user->fresh());

        $this->assertSame(1, $this->calls('DELETE', '/training-api/workout/v2/'));
        $this->assertSame(0, GarminScheduledWorkout::count());
    }

    public function test_the_profile_switch_turns_it_off(): void
    {
        $this->actingAs($this->user)
            ->patch(route('profile.garmin-workout-sync'), ['enabled' => false])
            ->assertRedirect();

        $this->assertFalse($this->user->fresh()->syncsPlanToGarmin());
        Queue::assertPushed(SyncGarminWorkoutsJob::class);
    }

    public function test_the_watch_button_is_hidden_for_a_garmin_athlete(): void
    {
        $this->assertTrue($this->user->sendsWorkoutsToGarmin());
    }
}
