<?php

namespace Tests\Feature;

use App\Http\Controllers\OnboardingController;
use App\Jobs\SyncCorosWorkoutsJob;
use App\Models\CorosConnection;
use App\Models\CorosScheduledWorkout;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Coros\CorosClient;
use App\Services\Coros\CorosDisconnector;
use App\Services\Coros\CorosWorkoutBuilder;
use App\Services\Coros\CorosWorkoutSync;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * COROS ueber MCP: Verbindung und Plan auf die Uhr (heute und morgen).
 *
 * Die Antworten im Fake sind die echten Texte aus dem Probelauf mit Jans
 * Konto am 05.11.2026 („Workout created on …\nidInPlan: 1 …“). MCP kann im
 * Kalender nicht loeschen — darum prueft der Test vor allem, dass Zone3
 * ersetzt, absagt und Plaetze wiederverwendet, statt Workouts anzuhaeufen.
 */
class CorosWorkoutSyncTest extends TestCase
{
    use RefreshDatabase;

    // Mittwoch. Der Vereinstermin liegt mittwochs.
    private const TODAY = '2026-11-04 08:00:00';

    private const BASE = 'https://mcpeu.coros.com';

    private User $user;
    private TrainingPlan $plan;
    private int $nextId = 1;
    private int $status = 200;
    private bool $toolError = false;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY);
        config(['services.coros.enabled' => true, 'services.coros.base_url' => self::BASE, 'services.coros.client_id' => null]);
        Queue::fake();

        $this->user = User::factory()->onboarded()->create();

        CorosConnection::create([
            'user_id'                 => $this->user->id,
            'coros_user_id'           => 'c-1',
            'client_id'               => 'client-1',
            'access_token'            => 'tok',
            'refresh_token'           => 'ref',
            'access_token_expires_at' => now()->addDays(20),
        ]);

        RunnerProfile::create([
            'user_id'             => $this->user->id,
            'weekly_availability' => [
                'wednesday' => ['available' => true, 'duration_min' => 90, 'fixed' => ['type' => 'interval', 'label' => 'Laufclub']],
            ],
        ]);

        $event = Event::create([
            'user_id' => $this->user->id, 'name' => 'Halbmarathon', 'event_date' => '2026-12-06',
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ]);
        $this->plan = TrainingPlan::create([
            'user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true,
        ]);

        $this->fakeCoros();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fakeCoros(): void
    {
        Http::fake(function (Request $request) {
            if ($this->status !== 200) {
                return Http::response('', $this->status);
            }
            if (str_ends_with($request->url(), '/connect/register')) {
                return Http::response(['client_id' => 'client-new', 'redirect_uris' => $request['redirect_uris']]);
            }
            if (str_ends_with($request->url(), '/oauth2/token')) {
                return Http::response([
                    'access_token'  => 'tok-new',
                    'refresh_token' => 'ref-new',
                    'expires_in'    => 2591999,
                    // {"sub":"coros-42"}
                    'id_token'      => 'x.' . rtrim(strtr(base64_encode('{"sub":"coros-42"}'), '+/', '-_'), '=') . '.y',
                ]);
            }
            if (str_ends_with($request->url(), '/oauth2/revoke')) {
                return Http::response('', 200);
            }
            if (str_ends_with($request->url(), '/mcp')) {
                $tool = $request['params']['name'];
                $args = $request['params']['arguments'];
                if ($this->toolError) {
                    return $this->tool('Invalid course: sections must not be empty', true);
                }
                if ($tool === 'createScheduledWorkout') {
                    $id = $this->nextId++;

                    return $this->tool(json_encode("Workout created on {$args['date']}: {$args['course']['courseName']} (Running).\nidInPlan: {$id}\nTo change this workout later, use queryScheduledWorkoutDetails then updateScheduledWorkout with this date and idInPlan. To move or remove it, use the COROS app. Do not show idInPlan to the user."));
                }
                if ($tool === 'updateScheduledWorkout') {
                    return $this->tool(json_encode("Scheduled workout updated successfully (full replacement).\nidInPlan: {$args['idInPlan']}"));
                }
            }

            return Http::response(null, 404);
        });
    }

    private function tool(string $text, bool $error = false)
    {
        return Http::response([
            'jsonrpc' => '2.0', 'id' => 1,
            'result'  => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $error],
        ]);
    }

    private function plannedSession(array $attrs = []): TrainingSession
    {
        return TrainingSession::create($attrs + [
            'user_id' => $this->user->id, 'training_plan_id' => $this->plan->id, 'event_id' => $this->plan->event_id,
            'planned_date' => '2026-11-05', 'type' => 'easy_run', 'title' => 'Lockerer Lauf',
            'description' => 'Ruhig laufen.', 'distance_km' => 8, 'duration_min' => 48,
            'pace_target' => '5:50-6:10', 'zone' => 2, 'intensity' => 'low', 'status' => 'planned',
        ]);
    }

    private function sync(): array
    {
        return app(CorosWorkoutSync::class)->sync($this->user->fresh());
    }

    /** @return list<array> die Argumente der Aufrufe dieses Werkzeugs */
    private function calls(string $tool): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/mcp') && $r['params']['name'] === $tool)
            ->map(fn ($pair) => $pair[0]['params']['arguments'])
            ->values()->all();
    }

    // ── Workout-Format ───────────────────────────────────────────────────

    public function test_steps_become_a_coros_course_with_interval_groups(): void
    {
        $course = app(CorosWorkoutBuilder::class)->build('4×1000 m', 'Intervalle', [
            ['type' => 'warmup', 'duration_min' => 15],
            ['type' => 'work', 'distance_m' => 1000, 'duration_min' => 4.2, 'pace_target' => '4:10-4:20', 'repetitions' => 4],
            ['type' => 'rest', 'duration_min' => 2, 'repetitions' => 4],
            ['type' => 'cooldown', 'duration_min' => 10],
        ]);

        $this->assertSame(1, $course['sportType']);
        $this->assertSame([
            ['sectionType' => 1, 'targetType' => 2, 'targetValue' => 900],
            ['intervalGroup' => true, 'repeats' => 4, 'sets' => [
                ['sectionType' => 2, 'targetType' => 1, 'targetValue' => 1000, 'intensityType' => 2, 'intensityValueStart' => 250, 'intensityValueEnd' => 260],
                ['sectionType' => 3, 'targetType' => 2, 'targetValue' => 120],
            ]],
            ['sectionType' => 4, 'targetType' => 2, 'targetValue' => 600],
        ], $course['sections']);
    }

    public function test_a_single_pace_becomes_a_range_and_long_repeats_split(): void
    {
        $this->assertSame([343, 379], CorosWorkoutBuilder::paceRange('6:00'));

        $course = app(CorosWorkoutBuilder::class)->build('30/30', null, [
            ['type' => 'work', 'duration_min' => 0.5, 'repetitions' => 24],
            ['type' => 'recovery', 'duration_min' => 0.5, 'repetitions' => 24],
        ]);

        $this->assertSame([20, 4], array_column($course['sections'], 'repeats'));
        // Pflichtfeld bei COROS.
        $this->assertNotSame('', $course['courseDescription']);
    }

    // ── Abgleich ─────────────────────────────────────────────────────────

    public function test_today_and_tomorrow_go_to_the_calendar_without_club_race_bike_or_strength(): void
    {
        $tomorrow = $this->plannedSession();
        $this->plannedSession(['planned_date' => '2026-11-04', 'type' => 'interval', 'title' => 'Laufclub']);
        $this->plannedSession(['planned_date' => '2026-11-05', 'type' => 'strength', 'title' => 'Kraft']);
        $this->plannedSession(['planned_date' => '2026-11-04', 'type' => 'cross_training', 'sport_type' => 'VirtualRide', 'title' => 'Zwift']);
        $this->plannedSession(['planned_date' => '2026-11-06', 'title' => 'Uebermorgen']);

        $result = $this->sync();

        $this->assertSame(1, $result['created']);
        $this->assertSame([$tomorrow->id], CorosScheduledWorkout::pluck('training_session_id')->all());
        $this->assertSame('20261105', $this->calls('createScheduledWorkout')[0]['date']);
        $this->assertSame('1', CorosScheduledWorkout::first()->id_in_plan);
    }

    public function test_an_unchanged_plan_costs_no_call(): void
    {
        $this->plannedSession();
        $this->sync();
        $this->sync();

        $this->assertCount(1, $this->calls('createScheduledWorkout'));
        $this->assertCount(0, $this->calls('updateScheduledWorkout'));
    }

    public function test_a_changed_session_replaces_its_slot_instead_of_adding_one(): void
    {
        $session = $this->plannedSession();
        $this->sync();

        $session->update(['distance_km' => 10, 'duration_min' => 60]);
        $result = $this->sync();

        $this->assertSame(1, $result['replaced']);
        $this->assertCount(1, $this->calls('createScheduledWorkout'));
        $update = $this->calls('updateScheduledWorkout')[0];
        $this->assertSame(['20261105', '1'], [$update['date'], $update['idInPlan']]);
        $this->assertSame(10000, $update['course']['sections'][0]['targetValue']);
    }

    public function test_a_moved_session_leaves_entfaellt_and_the_slot_is_reused(): void
    {
        $session = $this->plannedSession();
        $this->sync();

        // Auf uebermorgen verschoben: morgen steht „Entfaellt“.
        $session->update(['planned_date' => '2026-11-06']);
        $result = $this->sync();

        $this->assertSame(1, $result['cancelled']);
        $update = $this->calls('updateScheduledWorkout')[0];
        $this->assertStringStartsWith('Entfällt', $update['course']['courseName']);
        $this->assertTrue(CorosScheduledWorkout::first()->cancelled);

        // Eine neue Einheit fuer morgen nimmt den Platz, statt ein zweites
        // Workout danebenzulegen.
        $new = $this->plannedSession(['title' => 'Neu fuer morgen']);
        $result = $this->sync();

        $this->assertSame(['replaced' => 1, 'created' => 0], ['replaced' => $result['replaced'], 'created' => $result['created']]);
        $this->assertCount(1, $this->calls('createScheduledWorkout'));
        $row = CorosScheduledWorkout::first();
        $this->assertFalse($row->cancelled);
        $this->assertSame($new->id, $row->training_session_id);
    }

    public function test_a_completed_session_stays_untouched(): void
    {
        $session = $this->plannedSession(['planned_date' => '2026-11-04']);
        $this->sync();

        $session->update(['status' => 'completed']);
        $result = $this->sync();

        $this->assertSame(1, $result['kept']);
        $this->assertCount(0, $this->calls('updateScheduledWorkout'));
    }

    public function test_switching_sync_off_cancels_what_is_there(): void
    {
        $this->plannedSession();
        $this->sync();

        $this->user->corosConnection->update(['workout_sync' => false]);
        $result = $this->sync();

        $this->assertSame(1, $result['cancelled']);
    }

    public function test_a_rejected_workout_stops_the_run_without_throwing(): void
    {
        $this->plannedSession();
        $this->toolError = true;

        $result = $this->sync();

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, CorosScheduledWorkout::count());
    }

    public function test_a_session_change_queues_the_coros_sync(): void
    {
        $this->plannedSession();

        Queue::assertPushed(SyncCorosWorkoutsJob::class, fn ($job) => $job->userId === $this->user->id);
    }

    // ── Verbindung ───────────────────────────────────────────────────────

    public function test_connect_registers_a_client_and_redirects_to_coros(): void
    {
        $other = User::factory()->onboarded()->create();

        $response = $this->actingAs($other)->post(route('coros.connect'), [], ['X-Inertia' => 'true']);

        $response->assertStatus(409);
        $location = $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith(self::BASE . '/oauth2/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame(['client-new', 'S256', 'openid offline_access mcp.tools'], [$q['client_id'], $q['code_challenge_method'], $q['scope']]);
        $this->assertSame(url('/coros/callback'), $q['redirect_uri']);

        // Die Registrierung wird gemerkt, nicht bei jedem Verbinden wiederholt.
        $this->actingAs($other)->post(route('coros.connect'), [], ['X-Inertia' => 'true']);
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/connect/register'))->count());
    }

    /** Gemeldet von Jan (05.11.2026): im Verbinden-Schritt des Onboardings fehlte COROS. */
    public function test_coros_can_be_connected_from_the_onboarding_and_returns_there(): void
    {
        $newcomer = User::factory()->create(['onboarding_completed_at' => null]);

        $this->actingAs($newcomer)->get(route('onboarding'))
            ->assertInertia(fn ($page) => $page->where('connections.corosAvailable', true)->where('connections.coros', null));

        $response = $this->actingAs($newcomer)->post(route('coros.connect'), [], ['X-Inertia' => 'true']);
        $response->assertStatus(409);
        $response->assertSessionHas(OnboardingController::RESUME_KEY, 'connect');

        Cache::put('coros-oauth:onb', ['user_id' => $newcomer->id, 'verifier' => 'v', 'client_id' => 'client-new'], 600);
        $this->actingAs($newcomer)->get('/coros/callback?code=xyz&state=onb')->assertRedirect(route('onboarding'));
        $this->assertNotNull($newcomer->fresh()->corosConnection);
    }

    public function test_the_callback_stores_the_connection_without_a_session(): void
    {
        $other = User::factory()->onboarded()->create();
        Cache::put('coros-oauth:abc', ['user_id' => $other->id, 'verifier' => 'v', 'client_id' => 'client-new'], 600);

        $this->get('/coros/callback?code=xyz&state=abc')->assertOk();

        $connection = $other->fresh()->corosConnection;
        $this->assertSame(['coros-42', 'client-new', 'tok-new', 'ref-new'], [
            $connection->coros_user_id, $connection->client_id, $connection->access_token, $connection->refresh_token,
        ]);
        $this->assertArrayNotHasKey('access_token', $connection->toArray());

        // Der Code ist einmal benutzbar.
        $this->get('/coros/callback?code=xyz&state=abc');
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/oauth2/token'))->count());
    }

    public function test_one_coros_account_belongs_to_one_athlete(): void
    {
        $this->user->corosConnection->update(['coros_user_id' => 'coros-42']);
        $other = User::factory()->onboarded()->create();
        Cache::put('coros-oauth:abc', ['user_id' => $other->id, 'verifier' => 'v', 'client_id' => 'client-new'], 600);

        $this->get('/coros/callback?code=xyz&state=abc');

        $this->assertNull($other->fresh()->corosConnection);
    }

    public function test_an_expiring_token_is_refreshed_with_the_connections_own_client(): void
    {
        $this->user->corosConnection->update(['access_token_expires_at' => now()->addHour()]);

        app(CorosClient::class)->accessToken($this->user->corosConnection);

        $refresh = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/oauth2/token'))->first()[0];
        $this->assertSame(['refresh_token', 'ref', 'client-1'], [$refresh['grant_type'], $refresh['refresh_token'], $refresh['client_id']]);
        $this->assertSame('tok-new', $this->user->corosConnection->fresh()->access_token);
    }

    public function test_disconnect_cancels_upcoming_workouts_revokes_and_deletes(): void
    {
        $this->plannedSession();
        $this->sync();

        app(CorosDisconnector::class)->disconnect($this->user->fresh());

        $this->assertStringStartsWith('Entfällt', $this->calls('updateScheduledWorkout')[0]['course']['courseName']);
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/oauth2/revoke'))->count());
        $this->assertNull($this->user->fresh()->corosConnection);
        $this->assertSame(0, CorosScheduledWorkout::count());
    }

    public function test_the_profile_hides_coros_unless_enabled(): void
    {
        config(['services.coros.enabled' => false]);

        $this->actingAs($this->user)->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page->where('coros.available', false));

        $this->actingAs($this->user)->post(route('coros.connect'))->assertNotFound();
    }
}
