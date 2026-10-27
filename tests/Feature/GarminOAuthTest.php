<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GarminConnection;
use App\Models\GarminDailyMetric;
use App\Models\GarminWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GarminOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://diauth.garmin.com/di-oauth2-service/oauth/token';
    private const API       = 'https://apis.garmin.com/wellness-api/rest';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.garmin.client_id'     => 'client-123',
            'services.garmin.client_secret' => 'secret-456',
            'services.garmin.redirect'      => 'https://zone3.run/garmin/callback',
        ]);
    }

    private function athlete(): User
    {
        return User::factory()->create(['onboarding_completed_at' => now()]);
    }

    private function connection(User $user, array $overrides = []): GarminConnection
    {
        return GarminConnection::create($overrides + [
            'user_id'                 => $user->id,
            'garmin_user_id'          => 'garmin-' . $user->id,
            'access_token'            => 'access-old',
            'refresh_token'           => 'refresh-old',
            'access_token_expires_at' => now()->addHours(20),
            'permissions'             => ['ACTIVITY_EXPORT', 'HEALTH_EXPORT'],
            'ai_consent_at'           => now(),
        ]);
    }

    private function tokenResponse(string $suffix = 'new'): array
    {
        return [
            'access_token'             => "access-{$suffix}",
            'refresh_token'            => "refresh-{$suffix}",
            'expires_in'               => 86400,
            'refresh_token_expires_in' => 7775998,
            'token_type'               => 'bearer',
        ];
    }

    // ── Verbinden ────────────────────────────────────────────────────────

    public function test_connecting_requires_the_ai_consent(): void
    {
        $this->actingAs($this->athlete())
            ->post(route('garmin.connect'))
            ->assertSessionHasErrors('ai_consent');

        $this->assertNull(session('garmin_oauth'));
    }

    public function test_connect_sends_the_athlete_to_garmin_with_a_pkce_challenge(): void
    {
        $response = $this->actingAs($this->athlete())
            ->post(route('garmin.connect'), ['ai_consent' => true]);

        $pending = session('garmin_oauth');
        $this->assertNotEmpty($pending['verifier']);

        $location = $response->headers->get('Location') ?? $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://connect.garmin.com/oauth2Confirm?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($pending['state'], $query['state']);
        $this->assertSame('https://zone3.run/garmin/callback', $query['redirect_uri']);

        // Der Fingerabdruck geht zu Garmin, das Geheimnis bleibt hier.
        $expected = rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($expected, $query['code_challenge']);
        $this->assertStringNotContainsString($pending['verifier'], $location);
    }

    public function test_the_callback_stores_an_encrypted_connection(): void
    {
        Http::fake([
            self::TOKEN_URL           => Http::response($this->tokenResponse()),
            self::API . '/user/id'          => Http::response(['userId' => 'g-777']),
            self::API . '/user/permissions' => Http::response(['ACTIVITY_EXPORT', 'WORKOUT_IMPORT']),
        ]);

        $user = $this->athlete();

        $this->actingAs($user)
            ->withSession(['garmin_oauth' => ['state' => 'abc', 'verifier' => 'v-secret', 'consent_at' => now()->toIso8601String()]])
            ->get('/garmin/callback?code=the-code&state=abc')
            ->assertRedirect(route('profile.edit'));

        $connection = $user->fresh()->garminConnection;
        $this->assertSame('g-777', $connection->garmin_user_id);
        $this->assertSame('access-new', $connection->access_token);
        $this->assertTrue($connection->allows(GarminConnection::WORKOUTS));
        $this->assertFalse($connection->allows(GarminConnection::HEALTH));
        $this->assertNotNull($connection->ai_consent_at);

        // In der Datenbank steht der Token nur verschluesselt.
        $raw = DB::table('garmin_connections')->value('access_token');
        $this->assertNotSame('access-new', $raw);

        // ... und er wandert bei keiner Serialisierung mit.
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());

        Http::assertSent(fn ($request) => $request->url() === self::TOKEN_URL
            && $request['grant_type'] === 'authorization_code'
            && $request['code_verifier'] === 'v-secret'
            && $request['client_secret'] === 'secret-456');
    }

    public function test_a_callback_with_a_foreign_state_is_rejected(): void
    {
        Http::fake();
        $user = $this->athlete();

        $this->actingAs($user)
            ->withSession(['garmin_oauth' => ['state' => 'abc', 'verifier' => 'v', 'consent_at' => now()->toIso8601String()]])
            ->get('/garmin/callback?code=the-code&state=other')
            ->assertRedirect(route('profile.edit'));

        $this->assertNull($user->fresh()->garminConnection);
        Http::assertNothingSent();
    }

    public function test_one_garmin_account_belongs_to_one_athlete(): void
    {
        $owner = $this->athlete();
        $this->connection($owner, ['garmin_user_id' => 'g-777']);

        Http::fake([
            self::TOKEN_URL                 => Http::response($this->tokenResponse()),
            self::API . '/user/id'          => Http::response(['userId' => 'g-777']),
            self::API . '/user/permissions' => Http::response(['HEALTH_EXPORT']),
        ]);

        $other = $this->athlete();
        $this->actingAs($other)
            ->withSession(['garmin_oauth' => ['state' => 'abc', 'verifier' => 'v', 'consent_at' => now()->toIso8601String()]])
            ->get('/garmin/callback?code=c&state=abc');

        $this->assertNull($other->fresh()->garminConnection);
        $this->assertSame(1, GarminConnection::count());
    }

    // ── Tokens erneuern ──────────────────────────────────────────────────

    public function test_an_expiring_token_is_refreshed_and_the_new_refresh_token_kept(): void
    {
        Http::fake([self::TOKEN_URL => Http::response($this->tokenResponse('rotated'))]);

        $connection = $this->connection($this->athlete(), ['access_token_expires_at' => now()->addMinutes(5)]);

        $token = app(\App\Services\Garmin\GarminClient::class)->accessToken($connection);

        $this->assertSame('access-rotated', $token);
        $this->assertSame('refresh-rotated', $connection->fresh()->refresh_token);
        Http::assertSent(fn ($r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-old');
    }

    public function test_a_fresh_token_is_not_refreshed(): void
    {
        Http::fake();
        $connection = $this->connection($this->athlete());

        app(\App\Services\Garmin\GarminClient::class)->accessToken($connection);

        Http::assertNothingSent();
    }

    public function test_the_daily_command_refreshes_all_and_records_failures(): void
    {
        $good = $this->connection($this->athlete());
        $bad  = $this->connection($this->athlete(), ['refresh_token' => 'refresh-dead']);

        Http::fake(function ($request) {
            return $request['refresh_token'] === 'refresh-dead'
                ? Http::response(['error' => 'invalid_grant'], 400)
                : Http::response($this->tokenResponse('daily'));
        });

        $this->artisan('garmin:refresh-tokens')->assertSuccessful();

        $this->assertSame('refresh-daily', $good->fresh()->refresh_token);
        $this->assertNull($good->fresh()->refresh_error);
        $this->assertNotNull($bad->fresh()->refresh_error);
        $this->assertSame('refresh-dead', $bad->fresh()->refresh_token);
    }

    // ── Trennen ──────────────────────────────────────────────────────────

    public function test_disconnecting_deregisters_and_keeps_activities(): void
    {
        Http::fake([self::API . '/user/registration' => Http::response(null, 204)]);

        $user = $this->athlete();
        $this->connection($user);
        GarminDailyMetric::create(['user_id' => $user->id, 'date' => '2026-10-26', 'hrv' => 50]);
        $activity = Activity::create([
            'user_id' => $user->id, 'strava_id' => 1, 'name' => 'Lauf', 'type' => 'Run',
            'start_date' => now()->subDay(), 'distance' => 8000, 'moving_time' => 2400, 'elapsed_time' => 2400,
        ]);

        $this->actingAs($user)->delete(route('garmin.connection.destroy'))->assertRedirect(route('profile.edit'));

        $this->assertNull($user->fresh()->garminConnection);
        $this->assertSame(0, GarminDailyMetric::where('user_id', $user->id)->count());
        $this->assertNotNull($activity->fresh());
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/user/registration'));
    }

    public function test_disconnecting_works_even_when_garmin_does_not_answer(): void
    {
        Http::fake([self::API . '/user/registration' => Http::response(null, 500)]);

        $user = $this->athlete();
        $this->connection($user);

        $this->actingAs($user)->delete(route('garmin.connection.destroy'));

        $this->assertNull($user->fresh()->garminConnection);
    }

    // ── Webhook ──────────────────────────────────────────────────────────

    public function test_a_deregistration_disconnects_once_garmin_rejects_the_token(): void
    {
        Http::fake([self::API . '/user/permissions' => Http::response(['error' => 'unauthorized'], 401)]);

        $user = $this->athlete();
        $this->connection($user, ['garmin_user_id' => 'g-1']);
        GarminDailyMetric::create(['user_id' => $user->id, 'date' => '2026-10-26', 'hrv' => 50]);

        $this->postJson('/garmin/webhook/user', ['deregistrations' => [['userId' => 'g-1']]])->assertOk();

        $this->assertNull($user->fresh()->garminConnection);
        $this->assertSame(0, GarminDailyMetric::where('user_id', $user->id)->count());
        $this->assertSame(GarminWebhookEvent::OUTCOME_DEREGISTERED, GarminWebhookEvent::first()->outcome);
        // Garmin hat selbst getrennt — dort gibt es nichts mehr aufzuheben.
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_a_forged_deregistration_changes_nothing(): void
    {
        Http::fake([self::API . '/user/permissions' => Http::response(['HEALTH_EXPORT'])]);

        $user = $this->athlete();
        $this->connection($user, ['garmin_user_id' => 'g-1']);

        $this->postJson('/garmin/webhook/user', ['deregistrations' => [['userId' => 'g-1']]])->assertOk();

        $this->assertNotNull($user->fresh()->garminConnection);
        $this->assertSame(GarminWebhookEvent::OUTCOME_STILL_CONNECTED, GarminWebhookEvent::first()->outcome);
    }

    public function test_a_network_failure_never_disconnects(): void
    {
        Http::fake([self::API . '/user/permissions' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $user = $this->athlete();
        $this->connection($user, ['garmin_user_id' => 'g-1']);

        $this->postJson('/garmin/webhook/user', ['deregistrations' => [['userId' => 'g-1']]])->assertOk();

        $this->assertNotNull($user->fresh()->garminConnection);
        $this->assertSame(GarminWebhookEvent::OUTCOME_CHECK_FAILED, GarminWebhookEvent::first()->outcome);
    }

    public function test_permissions_come_from_garmin_not_from_the_webhook_body(): void
    {
        Http::fake([self::API . '/user/permissions' => Http::response(['HEALTH_EXPORT'])]);

        $user = $this->athlete();
        $this->connection($user, ['garmin_user_id' => 'g-1']);

        $this->postJson('/garmin/webhook/user', ['userPermissionsChange' => [[
            'userId' => 'g-1', 'permissions' => ['ACTIVITY_EXPORT', 'WORKOUT_IMPORT', 'HEALTH_EXPORT'],
        ]]])->assertOk();

        $this->assertSame(['HEALTH_EXPORT'], $user->fresh()->garminConnection->permissions);
    }

    public function test_an_unknown_garmin_user_is_recorded_and_ignored(): void
    {
        Http::fake();

        $this->postJson('/garmin/webhook/user', ['deregistrations' => [['userId' => 'nobody']]])->assertOk();

        $this->assertSame(GarminWebhookEvent::OUTCOME_UNKNOWN_USER, GarminWebhookEvent::first()->outcome);
        Http::assertNothingSent();
    }

    // ── Konto ────────────────────────────────────────────────────────────

    public function test_deleting_the_account_deregisters_at_garmin(): void
    {
        Http::fake([self::API . '/user/registration' => Http::response(null, 204)]);

        $user = $this->athlete();
        $this->connection($user);

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);

        $this->assertNull(User::find($user->id));
        $this->assertSame(0, GarminConnection::count());
        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_the_profile_shows_no_tokens(): void
    {
        $user = $this->athlete();
        $this->connection($user);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('garminOfficial.available', true)
                ->where('garminOfficial.connection.health', true)
                ->missing('garminOfficial.connection.access_token'));
    }
}
