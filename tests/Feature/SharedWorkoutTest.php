<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Geteilte Workouts.
 *
 * Vorgeschlagen: „Aktuell kann man selber Workouts anlegen. Das wäre doch
 * cool wenn man diese auch auf Public setzten könnte. Dann könnten diese
 * auch andere Athleten nutzen … Workouts durch andere Athleten kann man
 * aber nicht ändern (Pace wird natürlich für jeden Athleten durch die
 * Schwellenpace berechnet)."
 *
 * Dass das überhaupt geht, liegt an einer Entscheidung im Builder: seine
 * Blöcke tragen `pace_zone`, keine festen Paces. Ein geteiltes Workout ist
 * damit schon strukturell athletenunabhängig — genau das prüfen die Tests
 * hier, denn es ist die Zusicherung, an der alles hängt.
 */
class SharedWorkoutTest extends TestCase
{
    use RefreshDatabase;

    private function athlete(float $thresholdMinPerKm = 5.0): User
    {
        $user = User::factory()->onboarded()->create();

        RunnerProfile::create([
            'user_id'         => $user->id,
            'threshold_speed' => $thresholdMinPerKm,
        ]);

        return $user;
    }

    private function workout(User $owner, bool $public = false): Workout
    {
        return Workout::create([
            'user_id'     => $owner->id,
            'name'        => '5×1000 m',
            'type'        => 'interval',
            'description' => 'Der Klassiker.',
            'is_public'   => $public,
            'published_at' => $public ? now() : null,
            'blocks'      => [
                ['type' => 'warmup', 'label' => 'Einlaufen', 'duration_mode' => 'time', 'duration_sec' => 600, 'pace_zone' => 1],
                [
                    'type' => 'repeat', 'repetitions' => 5,
                    'steps' => [
                        ['type' => 'work', 'label' => '1000 m', 'duration_mode' => 'distance', 'distance_m' => 1000, 'pace_zone' => 5],
                        ['type' => 'rest', 'label' => 'Trabpause', 'duration_mode' => 'distance', 'distance_m' => 400, 'pace_zone' => 1],
                    ],
                ],
                ['type' => 'cooldown', 'label' => 'Auslaufen', 'duration_mode' => 'time', 'duration_sec' => 600, 'pace_zone' => 1],
            ],
        ]);
    }

    private function plannedSession(User $user): TrainingSession
    {
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Zielrennen',
            'event_date' => now()->addDays(40), 'race_distance' => 'marathon',
            'priority' => 'A', 'target_time_hours' => 3, 'target_time_minutes' => 30,
        ]);

        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        return TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => now()->toDateString(), 'type' => 'easy_run',
            'title' => 'Lockere 30 Minuten', 'distance_km' => 5, 'duration_min' => 30,
            'intensity' => 'low', 'status' => 'planned',
        ]);
    }

    // ── Freigeben ────────────────────────────────────────────────────────

    public function test_the_creator_can_share_and_unshare(): void
    {
        $user    = $this->athlete();
        $workout = $this->workout($user);

        $this->actingAs($user)->postJson(route('workouts.toggle-public', $workout->id))->assertOk();
        $this->assertTrue($workout->fresh()->is_public);
        $this->assertNotNull($workout->fresh()->published_at);

        $this->actingAs($user)->postJson(route('workouts.toggle-public', $workout->id))->assertOk();
        $this->assertFalse($workout->fresh()->is_public);
    }

    public function test_nobody_else_can_share_my_workout(): void
    {
        $mine    = $this->workout($this->athlete());
        $other   = $this->athlete();

        $this->actingAs($other)
            ->postJson(route('workouts.toggle-public', $mine->id))
            ->assertForbidden();

        $this->assertFalse($mine->fresh()->is_public);
    }

    // ── Die Zusicherung, an der alles hängt ──────────────────────────────

    /**
     * Zwei Athleten, dasselbe Workout, verschiedene Paces.
     *
     * Geteilt wird die Struktur. Wäre die Pace mitgeteilt, wäre ein
     * geteiltes Workout für den einen ein lockerer Trab und für die andere
     * unlaufbar.
     */
    public function test_the_same_workout_gives_each_athlete_their_own_paces(): void
    {
        $fast = $this->athlete(4.0);
        $slow = $this->athlete(6.0);

        $workout = $this->workout($fast, public: true);

        $paceOf = function (User $u) use ($workout) {
            $data = $this->actingAs($u)->getJson(route('workouts.shared'))->json();

            return collect($data['workouts']['interval'])->firstWhere('id', $workout->id)['pace_target'];
        };

        $fastPace = $paceOf($fast);
        $slowPace = $paceOf($slow);

        $this->assertNotNull($fastPace);
        $this->assertNotSame($fastPace, $slowPace, 'Jeder Athlet bekommt seine eigene Pace');

        // Der schnellere Läufer hat die kleinere Zahl.
        $toSeconds = fn ($p) => ((int) explode(':', $p)[0]) * 60 + (int) explode(':', $p)[1];
        $this->assertLessThan($toSeconds($slowPace), $toSeconds($fastPace));
    }

    public function test_only_shared_workouts_are_listed(): void
    {
        $owner  = $this->athlete();
        $reader = $this->athlete();

        $this->workout($owner, public: false);
        $shared = $this->workout($owner, public: true);

        $data = $this->actingAs($reader)->getJson(route('workouts.shared'))->json();

        $ids = collect($data['workouts'])->flatten(1)->pluck('id');

        $this->assertTrue($ids->contains($shared->id));
        $this->assertSame(1, $ids->count(), 'Nicht freigegebene bleiben privat');
    }

    // ── Anwenden ─────────────────────────────────────────────────────────

    public function test_applying_replaces_todays_session_and_pins_it(): void
    {
        $owner   = $this->athlete();
        $athlete = $this->athlete();

        $workout = $this->workout($owner, public: true);
        $session = $this->plannedSession($athlete);

        $this->actingAs($athlete)
            ->post(route('workouts.apply-to-session', [$session->id, $workout->id]))
            ->assertRedirect();

        $session->refresh();

        $this->assertSame('interval', $session->type);
        $this->assertSame('5×1000 m', $session->title);
        $this->assertNotNull($session->steps, 'Die Struktur kommt aus dem Workout, nicht vom Modell');
        $this->assertNotNull($session->pinned_at, 'Die Entscheidung des Athleten überlebt die Neuberechnung');
        $this->assertSame(1, $workout->fresh()->times_used);
    }

    public function test_a_private_workout_of_someone_else_cannot_be_applied(): void
    {
        $owner   = $this->athlete();
        $athlete = $this->athlete();

        $workout = $this->workout($owner, public: false);
        $session = $this->plannedSession($athlete);

        $this->actingAs($athlete)
            ->post(route('workouts.apply-to-session', [$session->id, $workout->id]))
            ->assertForbidden();

        $this->assertSame('easy_run', $session->fresh()->type);
    }

    public function test_a_finished_session_is_not_overwritten(): void
    {
        $athlete = $this->athlete();
        $workout = $this->workout($athlete, public: true);
        $session = $this->plannedSession($athlete);
        $session->update(['status' => 'completed']);

        $this->actingAs($athlete)
            ->post(route('workouts.apply-to-session', [$session->id, $workout->id]));

        $this->assertSame('easy_run', $session->fresh()->type);
    }

    // ── Der Admin nimmt zurück ───────────────────────────────────────────

    /**
     * Ein Admin darf nur die Freigabe beenden — nicht ändern, nicht löschen.
     * Beim Ersteller bleibt das Workout stehen, mit dem Grund daneben.
     */
    public function test_an_admin_can_only_withdraw_the_sharing(): void
    {
        $owner   = $this->athlete();
        $workout = $this->workout($owner, public: true);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.workouts.unpublish', $workout->id), ['reason' => 'Zu hohe Belastung für Einsteiger.'])
            ->assertRedirect();

        $workout->refresh();

        $this->assertFalse($workout->is_public);
        $this->assertSame($owner->id, $workout->user_id, 'Es gehoert weiterhin seinem Ersteller');
        $this->assertSame('Zu hohe Belastung für Einsteiger.', $workout->unpublished_reason);
        $this->assertSame('5×1000 m', $workout->name, 'Der Inhalt bleibt unangetastet');
    }

    public function test_a_normal_user_cannot_withdraw_anything(): void
    {
        $workout = $this->workout($this->athlete(), public: true);

        $this->actingAs($this->athlete())
            ->post(route('admin.workouts.unpublish', $workout->id))
            ->assertForbidden();

        $this->assertTrue($workout->fresh()->is_public);
    }

    /**
     * Und wenn der Ersteller erneut freigibt, verschwindet der Vermerk —
     * sonst stünde dort dauerhaft ein Grund, der nicht mehr gilt.
     */
    public function test_resharing_clears_the_admin_note(): void
    {
        $owner   = $this->athlete();
        $workout = $this->workout($owner, public: true);

        $workout->update(['is_public' => false, 'unpublished_reason' => 'Zurückgezogen.']);

        $this->actingAs($owner)->postJson(route('workouts.toggle-public', $workout->id));

        $this->assertTrue($workout->fresh()->is_public);
        $this->assertNull($workout->fresh()->unpublished_reason);
    }
}
