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
 * Der geplante Abgleich — das Netz unter dem Webhook.
 *
 * Der Webhook war der EINZIGE automatische Weg, auf dem eine Aktivität
 * hereinkam. Als Stravas Zustellung ausblieb, merkte es niemand: sechs
 * Tage ohne Import, während die Subscription gültig war, der Endpunkt in
 * 0,35 s mit 200 antwortete und der Athlet lief.
 *
 * `strava:sync` läuft alle fünf Minuten und holt selbst. Der Webhook
 * bleibt der schnelle Weg; das hier macht aus „manchmal kaputt" ein
 * „manchmal fünf Minuten später".
 */
class StravaScheduledSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private StravaAccount $account;
    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.strava.client_id'     => 'test-client',
            'services.strava.client_secret' => 'test-secret',
            'services.strava.redirect'      => 'https://zone3.test/strava/callback',
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

    /** @param list<array<string, mixed>> $extra */
    private function stravaHas(array $ids = [998877], array $extra = []): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $detail = fn (int $id) => array_merge([
            'id'            => $id,
            'name'          => "Lauf {$id}",
            'type'          => 'Run',
            'distance'      => 12000,
            'moving_time'   => 3300,
            'elapsed_time'  => 3300,
            'average_speed' => 12000 / 3300,
            'start_date'    => now()->toIso8601String(),
        ], $extra);

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response(
                array_map(fn ($id) => ['id' => $id], $ids)
            ),
            'www.strava.com/api/v3/activities/*' => Http::response($detail($ids[0])),
        ]);
    }

    // ── Der Zweck ────────────────────────────────────────────────────────

    public function test_it_imports_an_activity_the_webhook_never_delivered(): void
    {
        $this->stravaHas();

        $this->artisan('strava:sync')->assertSuccessful();

        $this->assertDatabaseHas('activities', [
            'user_id'   => $this->user->id,
            'strava_id' => 998877,
        ]);
    }

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

        $this->stravaHas();
        $this->artisan('strava:sync');

        $session->refresh();

        $this->assertSame('completed', $session->status);
        $this->assertNotNull($session->activity_id);
    }

    // ── Was er NICHT tun darf ────────────────────────────────────────────

    /**
     * Die wichtigste Zusicherung: war der Webhook schneller, passiert hier
     * gar nichts. Sonst bekäme der Athlet zu jedem Lauf zwei Push-Nachrichten
     * und zwei Reviews.
     */
    public function test_an_activity_the_webhook_already_brought_in_is_left_alone(): void
    {
        Activity::create([
            'user_id' => $this->user->id, 'strava_id' => 998877, 'name' => 'Vom Webhook',
            'type' => 'Run', 'start_date' => now(), 'distance' => 12000,
            'moving_time' => 3300, 'elapsed_time' => 3300, 'average_speed' => 12000 / 3300,
        ]);

        $this->stravaHas();
        $this->artisan('strava:sync');

        $this->assertSame(1, Activity::where('strava_id', 998877)->count());
        $this->assertSame('Vom Webhook', Activity::where('strava_id', 998877)->first()->name);

        Queue::assertNotPushed(GenerateSessionReviewJob::class);
    }

    /** Zweimal laufen lassen darf nichts doppeln. */
    public function test_running_it_twice_changes_nothing(): void
    {
        $this->stravaHas();

        $this->artisan('strava:sync');
        $this->artisan('strava:sync');

        $this->assertSame(1, Activity::where('strava_id', 998877)->count());
        $this->assertSame(
            1,
            TrainingSession::where('user_id', $this->user->id)->where('was_unplanned', true)->count(),
        );
    }

    public function test_a_deleted_activity_does_not_come_back(): void
    {
        IgnoredStravaActivity::create(['user_id' => $this->user->id, 'strava_id' => 998877]);

        $this->stravaHas();
        $this->artisan('strava:sync');

        $this->assertDatabaseMissing('activities', ['strava_id' => 998877]);
    }

    /**
     * Ohne Refresh-Token kommt niemand mehr an einen Zugang — das Konto
     * wird übersprungen, ohne dass etwas nach draussen geht.
     */
    public function test_an_account_without_a_refresh_token_is_skipped(): void
    {
        $this->account->forceFill(['refresh_token' => ''])->save();

        Queue::fake();
        Http::preventStrayRequests();

        $this->artisan('strava:sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * Ein Konto, bei dem Strava klemmt, darf die anderen nicht mitreissen —
     * sonst hängt der Abgleich aller Athleten an einem kaputten Token.
     */
    public function test_one_broken_account_does_not_stop_the_others(): void
    {
        $second = User::factory()->onboarded()->create();
        StravaAccount::create([
            'user_id' => $second->id, 'strava_id' => 4712,
            'access_token' => 'tok2', 'refresh_token' => 'ref2',
            'token_expires_at' => now()->addDay(),
        ]);

        Queue::fake();
        Http::preventStrayRequests();

        // Die Liste schlaegt fehl (throw() im Service), die Detailabfrage
        // wuerde gehen — der Befehl muss trotzdem sauber durchlaufen.
        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response('', 500),
        ]);

        $this->artisan('strava:sync')->assertSuccessful();
    }

    // ── Ein Konto, das Strava ablehnt ────────────────────────────────────

    /**
     * Gefunden in der ersten Logzeile, die je in Produktion lesbar war:
     * „Strava-Sync fehlgeschlagen {user_id: 8, HTTP 401}". Der Athlet hatte
     * null Aktivitäten und nie einen Import — und die Systemseite zeigte ihn
     * trotzdem grün als „verbunden", weil ein Refresh-Token in der Datenbank
     * steht. Ein vorhandener Token und ein gültiger sind zwei Dinge.
     */
    public function test_a_rejected_account_is_marked(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['www.strava.com/api/v3/athlete/activities*' => Http::response('', 401)]);

        $this->artisan('strava:sync')->assertSuccessful();

        $account = $this->account->fresh();

        $this->assertNotNull($account->sync_error);
        $this->assertStringContainsString('401', $account->sync_error);
        $this->assertStringContainsString('neu verbinden', $account->sync_error);
        $this->assertNotNull($account->sync_error_at);
    }

    public function test_a_marked_account_is_no_longer_shown_as_connected(): void
    {
        $this->account->forceFill([
            'sync_error'    => 'Strava weist den Zugang ab (401) — der Athlet muss neu verbinden.',
            'sync_error_at' => now(),
        ])->save();

        $strava = app(\App\Services\SystemHealth::class)->integrations()['strava'];

        $this->assertFalse($strava[0]['connected']);
        $this->assertStringContainsString('401', $strava[0]['error']);
    }

    /**
     * Und der Vermerk verschwindet wieder, sobald es klappt — sonst stünde
     * „neu verbinden" noch da, nachdem der Athlet genau das getan hat.
     */
    public function test_a_successful_run_clears_the_mark(): void
    {
        $this->account->forceFill(['sync_error' => 'irgendwas', 'sync_error_at' => now()])->save();

        $this->stravaHas();
        $this->artisan('strava:sync')->assertSuccessful();

        $this->assertNull($this->account->fresh()->sync_error);
    }

    /**
     * Andere Fehler bekommen ihren eigenen Satz — ein erschöpftes Kontingent
     * geht von selbst vorbei, ein 401 nicht.
     */
    public function test_a_rate_limit_reads_differently_than_a_rejection(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['www.strava.com/api/v3/athlete/activities*' => Http::response('', 429)]);

        $this->artisan('strava:sync');

        $this->assertStringContainsString('Kontingent', $this->account->fresh()->sync_error);
    }

    public function test_a_single_account_can_be_targeted(): void
    {
        $second = User::factory()->onboarded()->create();
        StravaAccount::create([
            'user_id' => $second->id, 'strava_id' => 4712,
            'access_token' => 'tok2', 'refresh_token' => 'ref2',
            'token_expires_at' => now()->addDay(),
        ]);

        $this->stravaHas();

        $this->artisan("strava:sync --user={$this->user->id}")->assertSuccessful();

        $this->assertSame(1, Activity::count());
        $this->assertSame($this->user->id, Activity::first()->user_id);
    }
}
