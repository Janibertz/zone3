<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GarminConnection;
use App\Models\GarminScheduledWorkout;
use App\Models\RunnerProfile;
use App\Models\StrengthExercise;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Garmin\GarminStrengthBuilder;
use App\Services\Garmin\GarminWorkoutBuilder;
use App\Services\Garmin\GarminWorkoutSync;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Kraft auf der Garmin-Uhr (05.11.2026, Jan: „Können auch als Workout auf
 * die Uhr"). Eine Kraft-, Rumpf- oder Mobilitätseinheit mit Übungen aus dem
 * Katalog geht als STRENGTH_TRAINING-Workout über dieselbe Training API wie
 * die Läufe.
 */
class GarminStrengthWorkoutTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-05 08:00:00';

    private User $user;
    private TrainingPlan $plan;
    private int $nextId = 1000;
    private bool $rejectStrength = false;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY);
        config(['services.garmin.client_id' => 'c', 'services.garmin.client_secret' => 's']);
        Queue::fake();

        $this->user = User::factory()->onboarded()->create();
        GarminConnection::create([
            'user_id' => $this->user->id, 'garmin_user_id' => 'g-1', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'access_token_expires_at' => now()->addHours(20),
            'permissions' => [GarminConnection::WORKOUTS, GarminConnection::ACTIVITY], 'ai_consent_at' => now(),
        ]);
        RunnerProfile::create(['user_id' => $this->user->id, 'weekly_availability' => []]);

        $event = Event::create([
            'user_id' => $this->user->id, 'name' => 'Halbmarathon', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ]);
        $this->plan = TrainingPlan::create(['user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'DELETE') {
                return Http::response(null, 204);
            }
            if (str_contains($request->url(), '/workoutportal/workout/v2')) {
                return $this->rejectStrength && $request['sport'] === 'STRENGTH_TRAINING'
                    ? Http::response(['message' => 'Invalid exerciseName'], 400)
                    : Http::response(['workoutId' => $this->nextId++]);
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

    private function strength(array $attrs = []): TrainingSession
    {
        return TrainingSession::create($attrs + [
            'user_id' => $this->user->id, 'training_plan_id' => $this->plan->id, 'event_id' => $this->plan->event_id,
            'planned_date' => '2026-11-06', 'type' => 'strength', 'title' => 'Kraft für Läufer',
            'description' => 'Beine und Rumpf.', 'duration_min' => 35, 'intensity' => 'moderate', 'status' => 'planned',
            'exercises' => [
                ['key' => 'goblet_squat', 'name' => 'Goblet Squat', 'sets' => 3, 'reps' => '10', 'load' => '16 kg', 'note' => null],
                ['key' => 'side_plank', 'name' => 'Seitstütz', 'sets' => 2, 'reps' => '30 s je Seite', 'load' => null, 'note' => 'Hüfte hoch'],
                ['key' => 'single_leg_landing', 'name' => 'Einbeinige Landung', 'sets' => 2, 'reps' => '6 je Bein', 'load' => null, 'note' => null],
            ],
        ]);
    }

    public function test_the_start_catalog_knows_garmins_exercises(): void
    {
        $this->assertSame(48, StrengthExercise::whereNotNull('garmin_name')->count(), 'alle bis auf die einbeinige Landung');

        $list = StrengthExercise::garminExercises();
        foreach (StrengthExercise::whereNotNull('garmin_category')->get() as $e) {
            $this->assertContains($e->garmin_name, $list[$e->garmin_category] ?? [], "{$e->key}: Garmin kennt {$e->garmin_category}/{$e->garmin_name} nicht");
        }
    }

    public function test_a_strength_session_becomes_a_strength_workout(): void
    {
        $body = app(GarminWorkoutBuilder::class)->forSession($this->strength());

        $this->assertSame('STRENGTH_TRAINING', $body['sport']);
        $this->assertSame('STRENGTH_TRAINING', $body['segments'][0]['sport']);
        $steps = $body['segments'][0]['steps'];
        $this->assertCount(3, $steps, 'eine Wiederholung je Übung');

        [$squat, $plank, $landing] = $steps;
        $this->assertSame(['WorkoutRepeatStep', 1, 3], [$squat['type'], $squat['stepOrder'], $squat['repeatValue']]);

        [$work, $rest] = $squat['steps'];
        $this->assertSame([2, 'REPS', 10.0], [$work['stepOrder'], $work['durationType'], $work['durationValue']]);
        $this->assertSame(['SQUAT', 'GOBLET_SQUAT'], [$work['exerciseCategory'], $work['exerciseName']]);
        $this->assertSame([16.0, 'KILOGRAM'], [$work['weightValue'], $work['weightDisplayUnit']]);
        $this->assertSame([3, 'REST', 'TIME', 60.0], [$rest['stepOrder'], $rest['intensity'], $rest['durationType'], $rest['durationValue']]);

        // „30 s je Seite": der Satz ist erst mit beiden Seiten fertig.
        $this->assertSame(['TIME', 60.0], [$plank['steps'][0]['durationType'], $plank['steps'][0]['durationValue']]);
        $this->assertSame('Seitstütz · 30 s je Seite · Hüfte hoch', $plank['steps'][0]['description']);
        $this->assertNull($plank['steps'][0]['weightValue']);

        // Ohne Garmin-Übung nur der Name, keine halbe Angabe.
        $this->assertNull($landing['steps'][0]['exerciseCategory']);
        $this->assertNull($landing['steps'][0]['exerciseName']);
        $this->assertSame(12.0, $landing['steps'][0]['durationValue']);
    }

    public function test_reps_and_load_are_read_from_the_text(): void
    {
        $this->assertSame(['REPS', 10.0, null], GarminStrengthBuilder::amount('10'));
        $this->assertSame(['REPS', 10.0, null], GarminStrengthBuilder::amount('8–10'));
        $this->assertSame(['REPS', 16.0, 'je Bein'], GarminStrengthBuilder::amount('8 je Bein'));
        $this->assertSame(['TIME', 45.0, null], GarminStrengthBuilder::amount('45 s'));
        $this->assertSame(['TIME', 90.0, null], GarminStrengthBuilder::amount('1,5 min'));
        $this->assertSame(['OPEN', null, null], GarminStrengthBuilder::amount('bis kurz vor Ermüdung'));

        $this->assertSame(12.5, GarminStrengthBuilder::kilograms('12,5 kg'));
        $this->assertNull(GarminStrengthBuilder::kilograms('mittleres Band'));
    }

    public function test_core_rests_shorter_than_strength(): void
    {
        $body = app(GarminWorkoutBuilder::class)->forSession($this->strength(['type' => 'core']));

        $this->assertSame(30.0, $body['segments'][0]['steps'][0]['steps'][1]['durationValue']);
    }

    public function test_strength_with_exercises_goes_to_the_calendar(): void
    {
        $session = $this->strength();
        // Ohne Übungen gibt es nichts zu zeigen.
        $this->strength(['planned_date' => '2026-11-07', 'exercises' => null]);

        $result = app(GarminWorkoutSync::class)->sync($this->user->fresh());

        $this->assertSame(1, $result['created']);
        $this->assertSame([$session->id], GarminScheduledWorkout::pluck('training_session_id')->all());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/workoutportal/workout/v2') && $r['sport'] === 'STRENGTH_TRAINING');
    }

    /** Lehnt Garmin das Kraft-Workout ab, kommen die Läufe trotzdem auf die Uhr. */
    public function test_a_rejected_strength_workout_does_not_block_the_runs(): void
    {
        $this->rejectStrength = true;

        $this->strength(['planned_date' => '2026-11-05']);
        $run = TrainingSession::create([
            'user_id' => $this->user->id, 'training_plan_id' => $this->plan->id, 'event_id' => $this->plan->event_id,
            'planned_date' => '2026-11-07', 'type' => 'easy_run', 'title' => 'Lockerer Lauf', 'distance_km' => 8,
            'duration_min' => 48, 'pace_target' => '5:50-6:10', 'zone' => 2, 'intensity' => 'low', 'status' => 'planned',
        ]);

        $result = app(GarminWorkoutSync::class)->sync($this->user->fresh());

        $this->assertSame([1, 1], [$result['created'], $result['rejected']]);
        $this->assertSame([$run->id], GarminScheduledWorkout::pluck('training_session_id')->all());
    }

    public function test_the_button_sends_a_strength_session(): void
    {
        $session = $this->strength(['planned_date' => '2026-11-20']);

        $this->actingAs($this->user)
            ->postJson(route('training-sessions.send-to-garmin', $session))
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, GarminScheduledWorkout::count());
    }

    public function test_an_admin_change_reaches_the_watch(): void
    {
        $session = $this->strength();
        app(GarminWorkoutSync::class)->sync($this->user->fresh());

        $admin = User::factory()->create(['is_admin' => true]);
        $e = StrengthExercise::where('key', 'goblet_squat')->first();
        $this->actingAs($admin)->patch("/admin/strength-exercises/{$e->id}", [
            'name' => $e->name, 'group' => $e->group, 'equipment' => $e->equipment, 'level' => $e->level,
            'default_sets' => 3, 'default_reps' => '10', 'garmin_category' => 'SQUAT', 'garmin_name' => 'DUMBBELL_SQUAT',
        ])->assertRedirect()->assertSessionHasNoErrors();

        app(\App\Services\Strength\StrengthCatalog::class)->forget();
        $result = app(GarminWorkoutSync::class)->sync($this->user->fresh());

        // Geändert heißt bei Garmin: löschen und neu anlegen.
        $this->assertSame([1, 1], [$result['created'], $result['removed']]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/workoutportal/workout/v2')
            && $r['segments'][0]['steps'][0]['steps'][0]['exerciseName'] === 'DUMBBELL_SQUAT');
        $this->assertSame($session->id, GarminScheduledWorkout::first()->training_session_id);
    }

    public function test_the_admin_cannot_store_a_name_garmin_does_not_know(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $e = StrengthExercise::where('key', 'goblet_squat')->first();

        $this->actingAs($admin)->patch("/admin/strength-exercises/{$e->id}", [
            'name' => $e->name, 'group' => $e->group, 'equipment' => $e->equipment, 'level' => $e->level,
            'default_sets' => 3, 'default_reps' => '10', 'garmin_category' => 'SQUAT', 'garmin_name' => 'GOBLET_SQAUT',
        ])->assertSessionHasErrors('garmin_name');

        $this->assertSame('GOBLET_SQUAT', $e->fresh()->garmin_name);
    }
}
