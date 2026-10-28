<?php

namespace Tests\Feature;

use App\Jobs\GenerateSessionReviewJob;
use App\Models\Activity;
use App\Models\GarminConnection;
use App\Models\IgnoredActivity;
use App\Models\StravaAccount;
use App\Models\StravaWebhookEvent;
use App\Models\User;
use App\Services\ActivityDeletionService;
use App\Services\ActivityImportService;
use App\Services\StravaImportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Eine Einheit, eine Zeile — auch wenn sie aus zwei Quellen kommt.
 *
 * Schritt 3 der Garmin-Integration: Aktivitäten kommen aus Strava ODER
 * Garmin. Beim Wechsel der Quelle und bei Zwift (lädt zu beiden hoch) kommt
 * dieselbe Einheit trotzdem zweimal. Dann bekommt die bestehende Zeile die
 * zweite ID, und die Zahlen der ersten Quelle bleiben stehen.
 */
class ActivitySourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private StravaAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.strava.client_id'            => 'test-client',
            'services.strava.client_secret'        => 'test-secret',
            'services.strava.redirect'             => 'https://zone3.test/strava/callback',
            'services.strava.webhook_verify_token' => 'test-verify-token',
        ]);

        Queue::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-28 18:00:00');

        $this->user = User::factory()->onboarded()->create();

        $this->account = StravaAccount::create([
            'user_id'          => $this->user->id,
            'strava_id'        => 4711,
            'access_token'     => 'tok',
            'refresh_token'    => 'ref',
            'token_expires_at' => now()->addDay(),
        ]);
    }

    private function connectGarmin(): void
    {
        GarminConnection::create([
            'user_id'                 => $this->user->id,
            'garmin_user_id'          => 'g-' . $this->user->id,
            'access_token'            => 'a',
            'refresh_token'           => 'r',
            'access_token_expires_at' => now()->addHours(20),
            'permissions'             => [GarminConnection::ACTIVITY],
            'ai_consent_at'           => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function garminRun(array $overrides = []): Activity
    {
        return Activity::create(array_merge([
            'user_id'            => $this->user->id,
            'source'             => Activity::SOURCE_GARMIN,
            'garmin_activity_id' => 123456789,
            'name'               => 'Hannover Laufen',
            'type'               => 'Run',
            'distance'           => 10000,
            'moving_time'        => 2700,
            'elapsed_time'       => 2750,
            'average_speed'      => 10000 / 2700,
            'start_date'         => Carbon::parse('2026-10-28 07:00:00', 'UTC'),
            'average_heartrate'  => null,
        ], $overrides));
    }

    private function stravaDetail(array $overrides = []): array
    {
        return array_merge([
            'id'                => 998877,
            'name'              => 'Morgenlauf',
            'type'              => 'Run',
            'distance'          => 10120,
            'moving_time'       => 2710,
            'elapsed_time'      => 2760,
            'average_speed'     => 10120 / 2710,
            'average_heartrate' => 151.0,
            'start_date'        => '2026-10-28T07:00:40Z',
        ], $overrides);
    }

    private function importStrava(array $overrides = []): ?Activity
    {
        return app(StravaImportService::class)->importFromDetail($this->user->id, $this->stravaDetail($overrides));
    }

    // ── Abgleich ─────────────────────────────────────────────────────────

    public function test_the_same_run_from_strava_is_attached_not_duplicated(): void
    {
        $garmin = $this->garminRun();

        $activity = $this->importStrava();

        $this->assertSame($garmin->id, $activity->id);
        $this->assertFalse($activity->wasRecentlyCreated);
        $this->assertSame(1, Activity::count());
        $this->assertSame(998877, (int) $activity->strava_id);
    }

    public function test_the_first_source_keeps_its_numbers_and_gaps_are_filled(): void
    {
        $this->garminRun();

        $activity = $this->importStrava()->fresh();

        $this->assertSame(Activity::SOURCE_GARMIN, $activity->source);
        $this->assertEquals(10000, $activity->distance);
        $this->assertSame('Hannover Laufen', $activity->name);
        $this->assertEquals(151.0, $activity->average_heartrate);
    }

    public function test_a_start_more_than_two_minutes_apart_is_another_run(): void
    {
        $this->garminRun();

        $activity = $this->importStrava(['start_date' => '2026-10-28T07:03:30Z']);

        $this->assertTrue($activity->wasRecentlyCreated);
        $this->assertSame(2, Activity::count());
    }

    public function test_another_sport_at_the_same_time_is_another_activity(): void
    {
        $this->garminRun();

        $activity = $this->importStrava(['type' => 'Ride', 'distance' => 10000]);

        $this->assertTrue($activity->wasRecentlyCreated);
    }

    public function test_a_different_distance_is_another_activity(): void
    {
        $this->garminRun();

        $activity = $this->importStrava(['distance' => 12000]);

        $this->assertTrue($activity->wasRecentlyCreated);
    }

    public function test_without_distance_the_duration_decides(): void
    {
        $this->garminRun(['type' => 'WeightTraining', 'distance' => 0, 'moving_time' => 1800]);

        $activity = $this->importStrava(['type' => 'WeightTraining', 'distance' => 0, 'moving_time' => 1850]);

        $this->assertFalse($activity->wasRecentlyCreated);
        $this->assertSame(1, Activity::count());
    }

    public function test_garmins_upload_id_links_even_when_the_start_differs(): void
    {
        $garmin = $this->garminRun();

        $activity = $this->importStrava([
            'external_id' => 'garmin_push_123456789',
            'start_date'  => '2026-10-28T09:00:00Z',
        ]);

        $this->assertSame($garmin->id, $activity->id);
    }

    public function test_two_strava_activities_never_merge(): void
    {
        $first  = $this->importStrava();
        $second = $this->importStrava(['id' => 998878]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Activity::count());
    }

    public function test_a_garmin_row_with_a_strava_id_is_not_a_candidate_again(): void
    {
        $this->garminRun(['strava_id' => 111]);

        $activity = $this->importStrava();

        $this->assertTrue($activity->wasRecentlyCreated);
    }

    public function test_an_attached_row_is_refreshed_by_its_own_source(): void
    {
        $garmin = $this->garminRun();
        $this->importStrava();

        // Garmin stellt dieselbe Aktivität erneut zu — mit korrigierter Distanz.
        app(ActivityImportService::class)->store(
            $this->user->id, Activity::SOURCE_GARMIN, 123456789,
            ['distance' => 10050, 'name' => 'Hannover Laufen'],
        );

        $this->assertEquals(10050, $garmin->fresh()->distance);
        $this->assertSame(1, Activity::count());
    }

    // ── Webhook ──────────────────────────────────────────────────────────

    public function test_a_garmin_athlete_gets_nothing_from_the_strava_webhook(): void
    {
        $this->connectGarmin();
        $this->user->forceFill(['activity_source' => Activity::SOURCE_GARMIN])->save();

        // Kein Http::fake — ein Abruf bei Strava würde hier werfen.
        $this->postJson('/strava/webhook', [
            'object_type' => 'activity', 'aspect_type' => 'create',
            'owner_id' => 4711, 'object_id' => 998877,
        ])->assertOk();

        $this->assertSame(0, Activity::count());
        $this->assertSame(StravaWebhookEvent::OUTCOME_OTHER_SOURCE, StravaWebhookEvent::first()->outcome);
    }

    public function test_an_attached_activity_gets_no_second_review_or_push(): void
    {
        $this->garminRun();

        Http::fake(['www.strava.com/api/v3/activities/*' => Http::response($this->stravaDetail())]);

        $this->postJson('/strava/webhook', [
            'object_type' => 'activity', 'aspect_type' => 'create',
            'owner_id' => 4711, 'object_id' => 998877,
        ])->assertOk();

        $this->assertSame(1, Activity::count());
        $this->assertSame(StravaWebhookEvent::OUTCOME_ATTACHED, StravaWebhookEvent::first()->outcome);
        $this->assertSame(0, $this->user->trainingSessions()->count());
        Queue::assertNotPushed(GenerateSessionReviewJob::class);
    }

    public function test_deleting_on_strava_only_detaches_a_garmin_row(): void
    {
        $garmin = $this->garminRun(['strava_id' => 998877]);

        $this->postJson('/strava/webhook', [
            'object_type' => 'activity', 'aspect_type' => 'delete',
            'owner_id' => 4711, 'object_id' => 998877,
        ])->assertOk();

        $this->assertNotNull($garmin->fresh());
        $this->assertNull($garmin->fresh()->strava_id);
        $this->assertSame(StravaWebhookEvent::OUTCOME_DETACHED, StravaWebhookEvent::first()->outcome);
    }

    // ── Trennen und Löschen ──────────────────────────────────────────────

    public function test_disconnecting_strava_keeps_the_activities(): void
    {
        $this->importStrava();

        $this->actingAs($this->user)->delete(route('strava.disconnect'))->assertRedirect();

        $this->assertNull($this->user->fresh()->stravaAccount);
        $this->assertSame(1, Activity::count());
    }

    public function test_deleting_buries_every_source_id(): void
    {
        $activity = $this->garminRun(['strava_id' => 998877]);

        app(ActivityDeletionService::class)->delete($activity);

        $this->assertTrue(IgnoredActivity::blocks($this->user->id, 'strava', 998877));
        $this->assertTrue(IgnoredActivity::blocks($this->user->id, 'garmin', 123456789));
        $this->assertNull($this->importStrava());
    }

    // ── Quelle des Athleten ──────────────────────────────────────────────

    public function test_strava_is_the_source_by_default(): void
    {
        $this->assertSame(Activity::SOURCE_STRAVA, $this->user->activitySource());
    }

    public function test_an_explicit_choice_wins(): void
    {
        $this->connectGarmin();
        $this->user->forceFill(['activity_source' => Activity::SOURCE_GARMIN])->save();

        $this->assertSame(Activity::SOURCE_GARMIN, $this->user->fresh()->activitySource());
    }

    public function test_a_choice_that_cannot_deliver_falls_back(): void
    {
        // Garmin gewählt, aber keine Verbindung mit Aktivitäts-Freigabe.
        $this->user->forceFill(['activity_source' => Activity::SOURCE_GARMIN])->save();

        $this->assertSame(Activity::SOURCE_STRAVA, $this->user->fresh()->activitySource());
    }

    public function test_garmin_alone_is_the_source(): void
    {
        $this->account->delete();
        $this->connectGarmin();

        $this->assertSame(Activity::SOURCE_GARMIN, $this->user->fresh()->activitySource());
    }

    public function test_the_source_can_only_be_chosen_when_both_deliver(): void
    {
        $this->actingAs($this->user)
            ->patch(route('profile.activity-source'), ['source' => 'garmin'])
            ->assertSessionHasErrors('source');

        $this->connectGarmin();

        $this->actingAs($this->user->fresh())
            ->patch(route('profile.activity-source'), ['source' => 'garmin'])
            ->assertSessionHasNoErrors();

        $this->assertSame('garmin', $this->user->fresh()->activity_source);
    }
}
