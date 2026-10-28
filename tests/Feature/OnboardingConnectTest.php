<?php

namespace Tests\Feature;

use App\Http\Controllers\OnboardingController;
use App\Models\GarminConnection;
use App\Models\StravaAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Der letzte Onboarding-Schritt verbindet Garmin und Strava — gleichrangig,
 * auch beide (Entscheidung von Jan, 28.10.2026).
 *
 * Abgeschlossen wird erst mit „Fertig". Darum müssen beide Verbinden-Wege
 * vor dem Abschluss funktionieren und zurück ins Onboarding führen, statt
 * von der Onboarding-Middleware dorthin umgeleitet zu werden, bevor sie
 * überhaupt anfangen.
 */
class OnboardingConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.garmin.client_id'     => 'client-123',
            'services.garmin.client_secret' => 'secret-456',
            'services.garmin.redirect'      => 'https://zone3.run/garmin/callback',
            'services.strava.client_id'     => 'test-client',
            'services.strava.client_secret' => 'test-secret',
            'services.strava.redirect'      => 'https://zone3.test/strava/callback',
        ]);
    }

    private function newcomer(): User
    {
        return User::factory()->create(['onboarding_completed_at' => null]);
    }

    private function connectGarmin(User $user): void
    {
        GarminConnection::create([
            'user_id'                 => $user->id,
            'garmin_user_id'          => 'g-' . $user->id,
            'access_token'            => 'a',
            'refresh_token'           => 'r',
            'access_token_expires_at' => now()->addHours(20),
            'permissions'             => [GarminConnection::ACTIVITY],
            'ai_consent_at'           => now(),
        ]);
    }

    private function connectStrava(User $user): void
    {
        StravaAccount::create([
            'user_id'          => $user->id,
            'strava_id'        => 4711,
            'username'         => 'jan',
            'access_token'     => 'tok',
            'refresh_token'    => 'ref',
            'token_expires_at' => now()->addDay(),
        ]);
    }

    // ── Verbinden vor dem Abschluss ──────────────────────────────────────

    public function test_strava_can_be_connected_before_onboarding_is_complete(): void
    {
        $user = $this->newcomer();

        $response = $this->actingAs($user)->get(route('strava.connect'));

        $this->assertStringStartsWith('https://www.strava.com/oauth/authorize', $response->headers->get('Location'));
        $this->assertNull($user->fresh()->onboarding_completed_at);
        $response->assertSessionHas(OnboardingController::RESUME_KEY, 'connect');
    }

    public function test_the_strava_callback_returns_to_the_onboarding(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response([
            'access_token'  => 'acc',
            'refresh_token' => 'ref',
            'expires_at'    => now()->addHours(6)->timestamp,
            'athlete'       => ['id' => 4711, 'username' => 'jan'],
        ])]);

        $user = $this->newcomer();

        $this->actingAs($user)
            ->get('/strava/callback?code=the-code')
            ->assertRedirect(route('onboarding'));

        $this->assertNotNull($user->fresh()->stravaAccount);
        $this->assertNull($user->fresh()->onboarding_completed_at);
    }

    public function test_an_onboarded_athlete_still_lands_on_the_dashboard(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response([
            'access_token'  => 'acc',
            'refresh_token' => 'ref',
            'expires_at'    => now()->addHours(6)->timestamp,
            'athlete'       => ['id' => 4711],
        ])]);

        $this->actingAs(User::factory()->onboarded()->create())
            ->get('/strava/callback?code=the-code')
            ->assertRedirect(route('dashboard'));
    }

    public function test_garmin_can_be_connected_before_onboarding_is_complete(): void
    {
        $user = $this->newcomer();

        $response = $this->actingAs($user)->post(route('garmin.connect'), ['ai_consent' => true]);

        $location = $response->headers->get('Location') ?? $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://connect.garmin.com/oauth2Confirm?', $location);
        $response->assertSessionHas(OnboardingController::RESUME_KEY, 'connect');
    }

    public function test_the_garmin_callback_returns_to_the_onboarding(): void
    {
        Http::fake([
            'diauth.garmin.com/*'                                   => Http::response([
                'access_token' => 'acc', 'refresh_token' => 'ref',
                'expires_in' => 86400, 'refresh_token_expires_in' => 7775998,
            ]),
            'apis.garmin.com/wellness-api/rest/user/id'          => Http::response(['userId' => 'g-1']),
            'apis.garmin.com/wellness-api/rest/user/permissions' => Http::response(['ACTIVITY_EXPORT']),
        ]);

        $user = $this->newcomer();
        Cache::put('garmin-oauth:abc', [
            'user_id' => $user->id, 'verifier' => 'v', 'consent_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));

        $this->actingAs($user)
            ->get('/garmin/callback?code=the-code&state=abc')
            ->assertRedirect(route('onboarding'));

        $this->assertNotNull($user->fresh()->garminConnection);
    }

    // ── Der Schritt selbst ───────────────────────────────────────────────

    public function test_the_onboarding_resumes_at_the_connect_step(): void
    {
        $user = $this->newcomer();
        $this->connectStrava($user);
        $this->connectGarmin($user);

        $this->actingAs($user)
            ->withSession([OnboardingController::RESUME_KEY => 'connect'])
            ->get(route('onboarding'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding')
                ->where('resumeStep', 'connect')
                ->where('connections.strava.username', 'jan')
                ->where('connections.garmin.activities', true)
                ->where('connections.garminAvailable', true)
                ->where('connections.source.usable', ['strava', 'garmin']));
    }

    public function test_a_fresh_onboarding_starts_at_the_beginning(): void
    {
        $this->actingAs($this->newcomer())
            ->get(route('onboarding'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumeStep', null)
                ->where('connections.strava', null)
                ->where('connections.garmin', null));
    }

    public function test_the_source_can_be_chosen_during_onboarding(): void
    {
        $user = $this->newcomer();
        $this->connectStrava($user);
        $this->connectGarmin($user);

        $this->actingAs($user)
            ->from(route('onboarding'))
            ->patch(route('profile.activity-source'), ['source' => 'garmin'])
            ->assertRedirect(route('onboarding'));

        $this->assertSame('garmin', $user->fresh()->activity_source);
    }

    public function test_finishing_forgets_the_resume_step(): void
    {
        $user = $this->newcomer();

        $this->actingAs($user)
            ->withSession([OnboardingController::RESUME_KEY => 'connect'])
            ->post(route('onboarding.complete'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionMissing(OnboardingController::RESUME_KEY);

        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }
}
