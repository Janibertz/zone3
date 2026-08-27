<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\Event;
use App\Models\IgnoredStravaActivity;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Eine Aktivität löschen.
 *
 * Ging bis hierher nirgends — auch nicht im Admin-Bereich. Wer eine Einheit
 * zum Ausprobieren angelegt hatte, wurde sie nicht mehr los, und sie zählte
 * weiter in Wochenumfang, Belastung und Schwellenpace.
 *
 * Ein blosses Löschen genügt dabei nicht: `activity_id` ist `nullOnDelete`,
 * die abgehakte Einheit bliebe also auf „abgeschlossen" stehen — mit den
 * gelaufenen Werten, aber ohne den Beleg dafür.
 */
class DeleteActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->onboarded()->create();

        $event = Event::create([
            'user_id' => $this->user->id, 'name' => 'Zielrennen',
            'event_date' => now()->addDays(40), 'race_distance' => 'marathon',
            'priority' => 'A', 'target_time_hours' => 3, 'target_time_minutes' => 30,
        ]);

        $this->plan = TrainingPlan::create(['user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => []]);
        $this->plan->forceFill(['is_active' => true])->save();
    }

    private function activity(int $stravaId = 555001): Activity
    {
        return Activity::create([
            'user_id' => $this->user->id, 'strava_id' => $stravaId, 'name' => 'Testlauf',
            'type' => 'Run', 'start_date' => now(), 'distance' => 12000,
            'moving_time' => 3300, 'elapsed_time' => 3300, 'average_speed' => 12000 / 3300,
        ]);
    }

    // ── Die Aktivität selbst ─────────────────────────────────────────────

    public function test_an_activity_can_be_deleted(): void
    {
        $activity = $this->activity();

        $this->actingAs($this->user)
            ->deleteJson(route('activities.destroy', $activity))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNull(Activity::find($activity->id));
    }

    public function test_a_foreign_activity_is_refused(): void
    {
        $activity = $this->activity();
        $other    = User::factory()->onboarded()->create();

        $this->actingAs($other)
            ->deleteJson(route('activities.destroy', $activity))
            ->assertForbidden();

        $this->assertNotNull(Activity::find($activity->id));
    }

    public function test_deleting_requires_a_session(): void
    {
        $this->deleteJson(route('activities.destroy', $this->activity()))->assertUnauthorized();
    }

    // ── Die verknüpfte Einheit ───────────────────────────────────────────

    /**
     * Der Kern: eine geplante Einheit, die dieser Import abgehakt hat, wird
     * aus dem Schnappschuss wiederhergestellt — genau dafür gibt es ihn.
     */
    public function test_a_planned_session_is_restored_from_its_snapshot(): void
    {
        $activity = $this->activity();

        $session = TrainingSession::create([
            'user_id' => $this->user->id, 'training_plan_id' => $this->plan->id,
            'planned_date' => now()->toDateString(),
            'type' => 'easy_run', 'title' => 'Import-Titel',
            'description' => '', 'intensity' => 'medium',
            'distance_km' => 16.4, 'duration_min' => 88, 'pace_target' => '5:22',
            'status' => 'completed', 'activity_id' => $activity->id,
            'was_unplanned' => false,
            'planned_snapshot' => [
                'type' => 'tempo_run', 'title' => 'Tempolauf',
                'distance_km' => 12, 'duration_min' => 55,
                'pace_target' => '4:30-4:45', 'zone' => 4, 'intensity' => 'high',
            ],
            'coach_review' => 'Starke Einheit!', 'reviewed_at' => now(), 'rating' => 5,
        ]);

        $this->actingAs($this->user)
            ->deleteJson(route('activities.destroy', $activity))
            ->assertOk()
            ->assertJson(['restored' => 1, 'deleted' => 0]);

        $session->refresh();

        $this->assertSame('planned', $session->status);
        $this->assertSame('tempo_run', $session->type);
        $this->assertSame('Tempolauf', $session->title);
        $this->assertSame(12.0, (float) $session->distance_km);
        $this->assertSame('4:30-4:45', $session->pace_target);
        $this->assertNull($session->activity_id);
        $this->assertNull($session->planned_snapshot);

        // Das Review beschrieb einen Lauf, den es nicht mehr gibt.
        $this->assertNull($session->coach_review);
        $this->assertNull($session->reviewed_at);
        $this->assertNull($session->rating);
    }

    /** Was der Import angelegt hat, verschwindet mit ihm. */
    public function test_an_unplanned_session_is_deleted_with_the_activity(): void
    {
        $activity = $this->activity();

        $session = TrainingSession::create([
            'user_id' => $this->user->id, 'training_plan_id' => $this->plan->id,
            'planned_date' => now()->toDateString(), 'type' => 'easy_run',
            'title' => 'Spontanrunde', 'description' => '', 'intensity' => 'medium',
            'status' => 'completed', 'activity_id' => $activity->id, 'was_unplanned' => true,
        ]);

        $this->actingAs($this->user)
            ->deleteJson(route('activities.destroy', $activity))
            ->assertOk()
            ->assertJson(['deleted' => 1, 'restored' => 0]);

        $this->assertNull(TrainingSession::find($session->id));
    }

    /** Ohne Schnappschuss bleiben die Zahlen stehen — erfinden wäre schlimmer. */
    public function test_without_a_snapshot_the_status_is_still_reset(): void
    {
        $activity = $this->activity();

        $session = TrainingSession::create([
            'user_id' => $this->user->id, 'training_plan_id' => $this->plan->id,
            'planned_date' => now()->toDateString(), 'type' => 'easy_run',
            'title' => 'Alte Einheit', 'description' => '', 'intensity' => 'low',
            'distance_km' => 10, 'status' => 'completed',
            'activity_id' => $activity->id, 'was_unplanned' => false,
        ]);

        $this->actingAs($this->user)->deleteJson(route('activities.destroy', $activity))->assertOk();

        $session->refresh();
        $this->assertSame('planned', $session->status);
        $this->assertSame(10.0, (float) $session->distance_km);
    }

    // ── Was sonst daran hängt ────────────────────────────────────────────

    public function test_best_efforts_go_with_it(): void
    {
        $activity = $this->activity();

        BestEffort::create([
            'user_id' => $this->user->id, 'activity_id' => $activity->id,
            'distance_key' => '5k', 'distance_m' => 5000,
            'elapsed_time' => 1200, 'achieved_at' => now(),
        ]);

        $this->actingAs($this->user)->deleteJson(route('activities.destroy', $activity))->assertOk();

        $this->assertSame(0, BestEffort::where('activity_id', $activity->id)->count());
    }

    /** Die Renn-Analyse verweist auf die Aktivität — ohne sie ist sie unbelegt. */
    public function test_a_race_analysis_pointing_at_it_is_cleared(): void
    {
        $activity = $this->activity();
        $this->plan->update([
            'race_analysis_text'        => 'Starkes Rennen!',
            'race_analysis_activity_id' => $activity->id,
        ]);

        $this->actingAs($this->user)->deleteJson(route('activities.destroy', $activity))->assertOk();

        $this->plan->refresh();
        $this->assertNull($this->plan->race_analysis_text);
        $this->assertNull($this->plan->race_analysis_activity_id);
    }

    // ── Sie kommt nicht zurück ───────────────────────────────────────────

    /**
     * Ohne Grabstein legt der manuelle Strava-Abgleich sie beim nächsten Lauf
     * wieder an — `updateOrCreate` kennt den Löschwunsch nicht.
     */
    public function test_a_tombstone_survives_the_deletion(): void
    {
        $activity = $this->activity(stravaId: 777123);

        $this->actingAs($this->user)->deleteJson(route('activities.destroy', $activity))->assertOk();

        $this->assertTrue(
            IgnoredStravaActivity::where('user_id', $this->user->id)->where('strava_id', 777123)->exists(),
        );
        $this->assertContains(777123, IgnoredStravaActivity::idsFor($this->user->id));
    }

    /**
     * Der eigentliche Beweis: der manuelle Abgleich holt sie nicht zurück.
     * Ohne Grabstein legt `updateOrCreate` sie beim nächsten Lauf wieder an.
     */
    public function test_the_sync_does_not_bring_it_back(): void
    {
        $activity = $this->activity(stravaId: 888999);

        \App\Models\StravaAccount::create([
            'user_id' => $this->user->id, 'strava_id' => '4242',
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($this->user)->deleteJson(route('activities.destroy', $activity))->assertOk();

        // Strava kennt sie weiterhin und liefert sie beim Abgleich mit.
        $this->mock(\App\Services\StravaService::class, function ($m) {
            $m->shouldReceive('fetchRecentActivities')->andReturn([[
                'id' => 888999, 'name' => 'Testlauf', 'type' => 'Run',
                'distance' => 12000, 'moving_time' => 3300, 'elapsed_time' => 3300,
                'average_speed' => 12000 / 3300,
                'start_date' => now()->toIso8601String(),
            ]]);
        });
        $this->mock(\App\Services\BestEffortService::class, fn ($m) => $m->shouldReceive('recordFor')->andReturnNull());

        $this->actingAs($this->user)->get(route('strava.sync'));

        $this->assertSame(
            0,
            Activity::where('user_id', $this->user->id)->where('strava_id', 888999)->count(),
            'Der Abgleich darf eine geloeschte Aktivitaet nicht zurueckholen',
        );
    }

    /** Der Grabstein gilt nur für den Athleten, der gelöscht hat. */
    public function test_the_tombstone_is_scoped_to_the_athlete(): void
    {
        $activity = $this->activity(stravaId: 777124);
        $other    = User::factory()->onboarded()->create();

        $this->actingAs($this->user)->deleteJson(route('activities.destroy', $activity))->assertOk();

        $this->assertSame([], IgnoredStravaActivity::idsFor($other->id));
    }
}
