<?php

namespace Tests\Feature;

use App\Jobs\GenerateSessionReviewJob;
use App\Models\Activity;
use App\Models\Event;
use App\Models\IgnoredStravaActivity;
use App\Models\StravaAccount;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Der Strava-Webhook — der Import läuft im Request.
 *
 * Er lief eine Zeit lang in einem Job. Der Grund war gut: der Webserver ist
 * einthreadig, und ein Import mit zwei ausgehenden HTTP-Aufrufen blockiert
 * ihn. Nur kamen danach keine Aktivitäten mehr an, und auch eine eigene
 * Queue mit eigenem Worker hat das nicht behoben. Ein Kernfeature, das
 * nicht funktioniert, wiegt schwerer als ein langsamer Request — also
 * zurück auf den Stand, der lief.
 *
 * Was hier geprüft wird, ist deshalb das Gegenteil von vorher: der Request
 * SOLL die Arbeit tun. Am Ende muss die Aktivität in der Datenbank stehen
 * und der geplanten Einheit zugeordnet sein.
 */
class StravaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private StravaAccount $account;
    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        // `StravaService` liest seine Zugangsdaten im Konstruktor in
        // typisierte string-Eigenschaften. Fehlen sie — in CI wird
        // .env.example kopiert —, kommt null an und der Container wirft
        // einen TypeError. Ein Test soll nicht davon abhängen, was zufällig
        // in der Umgebung steht.
        config([
            'services.strava.client_id'            => 'test-client',
            'services.strava.client_secret'        => 'test-secret',
            'services.strava.redirect'             => 'https://zone3.test/strava/callback',
            'services.strava.webhook_verify_token' => 'test-verify-token',
        ]);

        $this->user = User::factory()->onboarded()->create();

        $this->account = StravaAccount::create([
            'user_id'          => $this->user->id,
            'strava_id'        => 4711,
            'access_token'     => 'tok',
            'refresh_token'    => 'ref',
            'token_expires_at' => now()->addDay(),
        ]);

        $event = Event::create([
            'user_id' => $this->user->id, 'name' => 'Zielrennen',
            'event_date' => now()->addDays(40), 'race_distance' => 'marathon',
            'priority' => 'A', 'target_time_hours' => 3, 'target_time_minutes' => 30,
        ]);

        $this->plan = TrainingPlan::create([
            'user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => [],
        ]);
        $this->plan->forceFill(['is_active' => true])->save();
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'object_type' => 'activity',
            'aspect_type' => 'create',
            'owner_id'    => 4711,
            'object_id'   => 998877,
        ], $overrides);
    }

    /**
     * Strava antwortet mit der Detailansicht.
     *
     * `Queue::fake()` MUSS dabei sein: unter Test ist die Queue `sync`, und
     * der Review-Job spricht mit OpenAI. `Http::preventStrayRequests()`
     * ebenso — ein `Http::fake()` mit Muster laesst alles durch, was nicht
     * darauf passt.
     *
     * @param array<string, mixed> $overrides
     */
    private function stravaReturns(array $overrides = []): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        Http::fake([
            'www.strava.com/api/v3/activities/*' => Http::response(array_merge([
                'id'            => 998877,
                'name'          => 'Abendlauf',
                'type'          => 'Run',
                'distance'      => 12000,
                'moving_time'   => 3300,
                'elapsed_time'  => 3300,
                'average_speed' => 12000 / 3300,
                'start_date'    => now()->toIso8601String(),
            ], $overrides)),
        ]);
    }

    // ── Der Request tut die Arbeit ───────────────────────────────────────

    public function test_the_webhook_imports_the_activity(): void
    {
        $this->stravaReturns();

        $this->postJson('/strava/webhook', $this->event())->assertOk();

        $this->assertDatabaseHas('activities', [
            'user_id'   => $this->user->id,
            'strava_id' => 998877,
            'name'      => 'Abendlauf',
        ]);

        Queue::assertPushed(GenerateSessionReviewJob::class);
    }

    /**
     * Der eigentliche Zweck: die geplante Einheit wird abgehakt.
     */
    public function test_it_completes_the_planned_session(): void
    {
        $session = TrainingSession::create([
            'user_id'          => $this->user->id,
            'training_plan_id' => $this->plan->id,
            'event_id'         => $this->plan->event_id,
            'planned_date'     => now()->toDateString(),
            'type'             => 'easy_run',
            'title'            => 'Lockerer Lauf',
            'distance_km'      => 10,
            'duration_min'     => 55,
            'intensity'        => 'low',
            'status'           => 'planned',
        ]);

        $this->stravaReturns();
        $this->postJson('/strava/webhook', $this->event())->assertOk();

        $session->refresh();

        $this->assertSame('completed', $session->status);
        $this->assertNotNull($session->activity_id);
        $this->assertNotNull($session->planned_snapshot, 'Was geplant war, muss nachlesbar bleiben');
    }

    /**
     * Kein Throttle und keine Token-Pruefung mehr auf der Route — beides kam
     * mit dem Umbau und beides kann einen echten Aufruf abweisen. Der Test
     * haelt fest, dass ein blanker Aufruf durchkommt.
     */
    public function test_a_plain_call_is_not_rejected(): void
    {
        $this->stravaReturns();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/strava/webhook', $this->event())->assertOk();
        }

        $this->assertSame(1, Activity::where('strava_id', 998877)->count(),
            'Mehrfache Zustellung darf nichts doppeln');
    }

    // ── Was der Webhook ignoriert ────────────────────────────────────────

    public function test_an_unknown_athlete_is_ignored(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $this->postJson('/strava/webhook', $this->event(['owner_id' => 9999]))->assertOk();

        $this->assertSame(0, Activity::count());
        Http::assertNothingSent();
    }

    /**
     * Nur `create`. Ein Titel, den jemand nachträglich bei Strava ändert,
     * ist kein Grund, den Trainingsplan anzufassen.
     */
    public function test_only_new_activities_are_imported(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $this->postJson('/strava/webhook', $this->event(['aspect_type' => 'update']))->assertOk();
        $this->postJson('/strava/webhook', $this->event(['object_type' => 'athlete']))->assertOk();

        $this->assertSame(0, Activity::count());
        Http::assertNothingSent();
    }

    /**
     * Was der Athlet gelöscht hat, bleibt gelöscht.
     */
    public function test_a_deleted_activity_does_not_come_back(): void
    {
        IgnoredStravaActivity::create(['user_id' => $this->user->id, 'strava_id' => 998877]);

        $this->stravaReturns();
        $this->postJson('/strava/webhook', $this->event())->assertOk();

        $this->assertDatabaseMissing('activities', ['strava_id' => 998877]);
    }

    /**
     * Liefert Strava die Aktivität nicht aus, endet der Request ruhig — mit
     * einer Zeile im Log. Ein 500 wäre schlechter: Strava stellt dann
     * erneut zu und die Störung verstärkt sich.
     */
    public function test_an_unavailable_activity_ends_quietly(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['www.strava.com/*' => Http::response('', 404)]);

        $this->postJson('/strava/webhook', $this->event())->assertOk();

        $this->assertSame(0, Activity::count());
    }

    // ── Loeschen bei Strava schlaegt hierher durch ───────────────────────

    /**
     * Bis hierher kannte der Handler nur `create`. Loeschte der Athlet einen
     * Lauf bei Strava, blieb er in Zone3 stehen — und zaehlte weiter in
     * Wochenumfang, Belastung und Schwellenpace, fuer einen Lauf, den es
     * nicht mehr gibt.
     */
    public function test_a_delete_event_removes_the_activity(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        Activity::create([
            'user_id' => $this->user->id, 'strava_id' => 998877, 'name' => 'Morning Walk',
            'type' => 'Walk', 'start_date' => now(), 'distance' => 2000,
            'moving_time' => 1200, 'elapsed_time' => 1200, 'average_speed' => 2000 / 1200,
        ]);

        $this->postJson('/strava/webhook', $this->event(['aspect_type' => 'delete']))->assertOk();

        $this->assertDatabaseMissing('activities', ['strava_id' => 998877]);
    }

    /**
     * Loeschen laeuft ueber den Loeschdienst, nicht ueber ein blosses
     * `delete()`: eine geplante Einheit, die der Import abgehakt hat, muss
     * auf ihren Stand davor zurueck — sonst stuende sie auf „abgeschlossen"
     * mit Zahlen, fuer die es keinen Beleg mehr gibt.
     */
    public function test_the_planned_session_goes_back_to_planned(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $activity = Activity::create([
            'user_id' => $this->user->id, 'strava_id' => 998877, 'name' => 'Abendlauf',
            'type' => 'Run', 'start_date' => now(), 'distance' => 12000,
            'moving_time' => 3300, 'elapsed_time' => 3300, 'average_speed' => 12000 / 3300,
        ]);

        $session = TrainingSession::create([
            'user_id'          => $this->user->id,
            'training_plan_id' => $this->plan->id,
            'event_id'         => $this->plan->event_id,
            'activity_id'      => $activity->id,
            'planned_date'     => now()->toDateString(),
            'type'             => 'easy_run',
            'title'            => 'Lockerer Lauf',
            'distance_km'      => 12,
            'duration_min'     => 55,
            'intensity'        => 'low',
            'status'           => 'completed',
            'planned_snapshot' => [
                'type' => 'easy_run', 'title' => 'Lockerer Lauf',
                'distance_km' => 10, 'duration_min' => 55,
                'pace_target' => '5:30', 'zone' => 2, 'intensity' => 'low',
            ],
        ]);

        $this->postJson('/strava/webhook', $this->event(['aspect_type' => 'delete']))->assertOk();

        $session->refresh();

        $this->assertSame('planned', $session->status);
        $this->assertNull($session->activity_id);
        $this->assertSame(10.0, (float) $session->distance_km, 'Die geplanten Zahlen kommen zurueck');
    }

    /**
     * Eine Löschung, zu der es hier nichts gibt, ist kein Fehler — das
     * passiert bei jeder Aktivität, die nie importiert wurde.
     */
    public function test_a_delete_for_something_unknown_is_harmless(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $this->postJson('/strava/webhook', $this->event(['aspect_type' => 'delete']))->assertOk();

        $this->assertSame(0, Activity::count());
    }

    /**
     * Und eine Löschung holt nichts bei Strava ab — die Aktivität ist dort
     * ja gerade verschwunden.
     */
    public function test_a_delete_does_not_call_strava(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        Activity::create([
            'user_id' => $this->user->id, 'strava_id' => 998877, 'name' => 'Weg damit',
            'type' => 'Run', 'start_date' => now(), 'distance' => 12000,
            'moving_time' => 3300, 'elapsed_time' => 3300, 'average_speed' => 12000 / 3300,
        ]);

        $this->postJson('/strava/webhook', $this->event(['aspect_type' => 'delete']))->assertOk();

        Http::assertNothingSent();
    }

    /**
     * `update` bleibt unbeachtet: einen Titel, den jemand bei Strava
     * nachtraeglich aendert, muss der Trainingsplan nicht mitbekommen.
     */
    public function test_an_update_event_still_changes_nothing(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        Activity::create([
            'user_id' => $this->user->id, 'strava_id' => 998877, 'name' => 'Bleibt',
            'type' => 'Run', 'start_date' => now(), 'distance' => 12000,
            'moving_time' => 3300, 'elapsed_time' => 3300, 'average_speed' => 12000 / 3300,
        ]);

        $this->postJson('/strava/webhook', $this->event(['aspect_type' => 'update']))->assertOk();

        $this->assertDatabaseHas('activities', ['strava_id' => 998877, 'name' => 'Bleibt']);
    }

    // ── Der Handshake ────────────────────────────────────────────────────

    public function test_the_handshake_answers_with_the_challenge(): void
    {
        $this->getJson('/strava/webhook?' . http_build_query([
            'hub_mode'         => 'subscribe',
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge'    => 'abc123',
        ]))->assertOk()->assertJson(['hub.challenge' => 'abc123']);
    }

    public function test_a_wrong_verify_token_is_refused(): void
    {
        $this->getJson('/strava/webhook?' . http_build_query([
            'hub_mode'         => 'subscribe',
            'hub_verify_token' => 'falsch',
            'hub_challenge'    => 'abc123',
        ]))->assertStatus(401);
    }
}
