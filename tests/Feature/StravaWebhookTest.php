<?php

namespace Tests\Feature;

use App\Jobs\GenerateSessionReviewJob;
use App\Jobs\ImportStravaActivityJob;
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
 * Der Strava-Webhook.
 *
 * Er tat den gesamten Import im Request: die Aktivität bei Strava abholen,
 * bei abgelaufenem Token vorher noch den Token erneuern, speichern,
 * zuordnen, Bestzeiten schreiben, Review-Jobs anstossen, Push verschicken.
 * Der Webserver ist einthreadig — solange das lief, stand die Seite für
 * alle. Und Strava stellt dasselbe Ereignis erneut zu, wenn die Antwort auf
 * sich warten lässt: die Langsamkeit verstärkte sich selbst.
 *
 * Was hier geprüft wird, ist deshalb vor allem eine Abwesenheit — im
 * Request darf nichts mehr passieren ausser dem Weiterreichen.
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
        // .env.example kopiert, und dort standen sie nicht —, kommt null an
        // und der Container wirft einen TypeError. Ein Test soll nicht davon
        // abhaengen, was zufaellig in der Umgebung steht.
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

    // ── Der Request tut nichts mehr selbst ───────────────────────────────

    public function test_the_webhook_hands_the_import_to_a_job(): void
    {
        Queue::fake();

        $this->postJson('/strava/webhook', $this->event())->assertOk();

        Queue::assertPushed(
            ImportStravaActivityJob::class,
            fn ($job) => $job->accountId === $this->account->id && $job->stravaActivityId === 998877,
        );
    }

    /**
     * Der Import gehoert auf seine eigene Queue.
     *
     * Zone3 faehrt einen Worker fuer alles, was mit OpenAI spricht — ein Plan
     * braucht 30 bis 70 Sekunden, und der Worker laeuft mit --timeout=1800.
     * Solange der Import im Request lief, ging ihn dieser Rueckstau nichts
     * an. Ihn ohne eigene Queue in die Warteschlange zu setzen, hat genau das
     * kaputtgemacht: der Lauf kam an, die Aktivitaet Minuten spaeter oder nie.
     *
     * `startup.sh` startet fuer `imports` einen zweiten Worker. Faellt diese
     * Zuordnung weg, steht der Import wieder hinter der Plangenerierung —
     * ohne dass irgendetwas rot wuerde. Deshalb steht sie hier.
     */
    public function test_the_import_does_not_queue_behind_the_ai_jobs(): void
    {
        Queue::fake();

        $this->postJson('/strava/webhook', $this->event())->assertOk();

        Queue::assertPushedOn('imports', ImportStravaActivityJob::class);
    }

    /**
     * Der Kern der Sache: kein ausgehender HTTP-Aufruf im Request.
     *
     * `fetchActivity` hing hier drin, ohne eigenes Zeitlimit — der
     * Laravel-Standard sind 30 Sekunden, und bei abgelaufenem Token kam der
     * Refresh noch davor. So lange stand der Webserver.
     */
    public function test_the_webhook_talks_to_nobody(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $this->postJson('/strava/webhook', $this->event())->assertOk();

        Http::assertNothingSent();
    }

    public function test_an_unknown_athlete_is_ignored(): void
    {
        Queue::fake();

        $this->postJson('/strava/webhook', $this->event(['owner_id' => 9999]))->assertOk();

        Queue::assertNothingPushed();
    }

    /**
     * Nur `create`. Ein Titel, den jemand nachträglich bei Strava ändert,
     * ist kein Grund, den Trainingsplan anzufassen.
     */
    public function test_only_new_activities_start_an_import(): void
    {
        Queue::fake();

        $this->postJson('/strava/webhook', $this->event(['aspect_type' => 'update']))->assertOk();
        $this->postJson('/strava/webhook', $this->event(['object_type' => 'athlete']))->assertOk();

        Queue::assertNothingPushed();
    }

    // ── Das Token aus der registrierten URL ──────────────────────────────

    /**
     * Solange in der Callback-URL kein Token steht — der heutige Stand —,
     * gibt es nichts zu prüfen. Diese Prüfung darf niemanden aussperren,
     * der sie nie eingeschaltet hat.
     */
    public function test_without_a_token_in_the_callback_url_nothing_is_demanded(): void
    {
        Queue::fake();
        config(['services.strava.webhook_callback_url' => 'https://zone3.test/strava/webhook']);

        $this->postJson('/strava/webhook', $this->event())->assertOk();

        Queue::assertPushed(ImportStravaActivityJob::class);
    }

    public function test_a_token_in_the_callback_url_is_enforced(): void
    {
        Queue::fake();
        config(['services.strava.webhook_callback_url' => 'https://zone3.test/strava/webhook?token=geheim']);

        $this->postJson('/strava/webhook', $this->event())->assertStatus(401);
        Queue::assertNothingPushed();

        $this->postJson('/strava/webhook?token=falsch', $this->event())->assertStatus(401);
        Queue::assertNothingPushed();

        $this->postJson('/strava/webhook?token=geheim', $this->event())->assertOk();
        Queue::assertPushed(ImportStravaActivityJob::class);
    }

    public function test_the_handshake_uses_the_same_token(): void
    {
        config(['services.strava.webhook_callback_url' => 'https://zone3.test/strava/webhook?token=geheim']);

        $handshake = [
            'hub_mode'         => 'subscribe',
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge'    => 'abc123',
        ];

        $this->getJson('/strava/webhook?' . http_build_query($handshake))->assertStatus(401);

        $this->getJson('/strava/webhook?' . http_build_query($handshake + ['token' => 'geheim']))
            ->assertOk()
            ->assertJson(['hub.challenge' => 'abc123']);
    }

    // ── Der Job ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function stravaReturns(array $overrides = []): void
    {
        // Die Queue zuerst: `sync` fuehrt jeden dispatch sofort aus, und der
        // Review-Job spricht mit OpenAI. Ein Test darf kein Geld ausgeben.
        Queue::fake();

        // Und was trotzdem hinaus wollte, soll auffliegen statt rauszugehen.
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

    public function test_the_job_imports_the_activity(): void
    {
        $this->stravaReturns();

        (new ImportStravaActivityJob($this->account->id, 998877))
            ->handle(...array_values($this->jobDependencies()));

        $this->assertDatabaseHas('activities', [
            'user_id'   => $this->user->id,
            'strava_id' => 998877,
            'name'      => 'Abendlauf',
        ]);

        Queue::assertPushed(GenerateSessionReviewJob::class);
    }

    /**
     * Strava stellt dasselbe Ereignis erneut zu, wenn die Antwort ausbleibt,
     * und ein fehlgeschlagener Job wird wiederholt. Beides darf nichts
     * doppelt anlegen.
     */
    public function test_running_the_job_twice_changes_nothing(): void
    {
        $this->stravaReturns();

        foreach (range(1, 2) as $ignored) {
            (new ImportStravaActivityJob($this->account->id, 998877))
                ->handle(...array_values($this->jobDependencies()));
        }

        $this->assertSame(1, Activity::where('strava_id', 998877)->count());
        $this->assertSame(
            1,
            TrainingSession::where('user_id', $this->user->id)->where('was_unplanned', true)->count(),
            'Der ungeplante Lauf darf nur einmal im Plan stehen',
        );
    }

    /**
     * Was der Athlet gelöscht hat, bleibt gelöscht — der Grabstein gilt auch
     * für den Weg über den Webhook.
     */
    public function test_a_deleted_activity_does_not_come_back(): void
    {
        $this->stravaReturns();

        IgnoredStravaActivity::create(['user_id' => $this->user->id, 'strava_id' => 998877]);

        (new ImportStravaActivityJob($this->account->id, 998877))
            ->handle(...array_values($this->jobDependencies()));

        $this->assertDatabaseMissing('activities', ['strava_id' => 998877]);
    }

    /**
     * Strava liefert das Ereignis manchmal, bevor die Aktivität über die API
     * abrufbar ist. Ein stiller Abbruch verlöre den Lauf; werfen heisst,
     * dass die Queue es noch einmal versucht.
     */
    public function test_an_activity_strava_does_not_serve_yet_is_retried(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['www.strava.com/*' => Http::response('', 404)]);

        $this->expectException(\RuntimeException::class);

        (new ImportStravaActivityJob($this->account->id, 998877))
            ->handle(...array_values($this->jobDependencies()));
    }

    /** @return array<string, object> */
    private function jobDependencies(): array
    {
        return [
            'strava'      => app(\App\Services\StravaService::class),
            'importer'    => app(\App\Services\StravaImportService::class),
            'bestEfforts' => app(\App\Services\BestEffortService::class),
            'webPush'     => app(\App\Services\WebPushService::class),
        ];
    }
}
