<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\IgnoredStravaActivity;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aktivitäten im Admin-Bereich.
 *
 * Der Anlass steht wörtlich in einer Meldung: „ich habe keine Möglichkeit
 * eine Aktivität zu löschen … noch nicht mal im Admin Bereich." Der Athlet
 * kann das inzwischen selbst; ein Admin sah fremde Aktivitäten weiterhin
 * nirgends.
 */
class AdminActivityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function activity(User $user, string $name = 'Abendlauf', int $stravaId = 555001): Activity
    {
        return Activity::create([
            'user_id' => $user->id, 'strava_id' => $stravaId, 'name' => $name,
            'type' => 'Run', 'start_date' => now()->subDay(), 'distance' => 12000,
            'moving_time' => 3300, 'elapsed_time' => 3300, 'average_speed' => 12000 / 3300,
        ]);
    }

    // ── Zugang ───────────────────────────────────────────────────────────

    public function test_a_normal_user_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/activities')
            ->assertForbidden();
    }

    public function test_an_admin_sees_every_athletes_activities(): void
    {
        $one = User::factory()->create(['name' => 'Athlet Eins']);
        $two = User::factory()->create(['name' => 'Athlet Zwei']);

        $this->activity($one, 'Lauf von Eins', 1);
        $this->activity($two, 'Lauf von Zwei', 2);

        $props = $this->actingAs($this->admin())->get('/admin/activities')->viewData('page')['props'];

        $names = collect($props['activities']['data'])->pluck('name');

        $this->assertTrue($names->contains('Lauf von Eins'));
        $this->assertTrue($names->contains('Lauf von Zwei'));
    }

    public function test_the_list_can_be_narrowed_to_one_athlete(): void
    {
        $one = User::factory()->create();
        $two = User::factory()->create();

        $this->activity($one, 'Lauf von Eins', 1);
        $this->activity($two, 'Lauf von Zwei', 2);

        $props = $this->actingAs($this->admin())
            ->get("/admin/activities?user={$one->id}")
            ->viewData('page')['props'];

        $this->assertCount(1, $props['activities']['data']);
        $this->assertSame('Lauf von Eins', $props['activities']['data'][0]['name']);
    }

    /**
     * Hängt eine Einheit dran, ist das Löschen kein reines Aufräumen — der
     * Plan ändert sich mit. Das gehört vor die Entscheidung, nicht danach.
     */
    public function test_the_list_says_whether_a_session_hangs_on_it(): void
    {
        $user     = User::factory()->create();
        $activity = $this->activity($user);

        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Ziel', 'event_date' => now()->addDays(30),
            'race_distance' => 'marathon', 'priority' => 'A',
            'target_time_hours' => 3, 'target_time_minutes' => 30,
        ]);
        $plan = TrainingPlan::create([
            'user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true,
        ]);

        TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'activity_id' => $activity->id, 'planned_date' => now()->subDay()->toDateString(),
            'type' => 'easy_run', 'title' => 'Locker', 'intensity' => 'low', 'status' => 'completed',
        ]);

        $props = $this->actingAs($this->admin())->get('/admin/activities')->viewData('page')['props'];

        $this->assertSame(1, $props['activities']['data'][0]['sessions']);
    }

    // ── Löschen ──────────────────────────────────────────────────────────

    /**
     * Über den Löschdienst, nie direkt: ohne den Grabstein holt der nächste
     * Strava-Abgleich die Aktivität zurück.
     */
    public function test_deleting_leaves_a_tombstone(): void
    {
        $user     = User::factory()->create();
        $activity = $this->activity($user);

        $this->actingAs($this->admin())
            ->delete("/admin/activities/{$activity->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('activities', ['id' => $activity->id]);
        $this->assertTrue(
            IgnoredStravaActivity::where('user_id', $user->id)->where('strava_id', 555001)->exists(),
            'Ohne Grabstein kommt sie beim naechsten Abgleich zurueck',
        );
    }

    public function test_a_normal_user_cannot_delete(): void
    {
        $activity = $this->activity(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->delete("/admin/activities/{$activity->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('activities', ['id' => $activity->id]);
    }
}
