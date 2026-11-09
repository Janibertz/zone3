<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Notifications\WaitlistApprovedNotification;
use App\Services\Auth\Access;
use App\Services\Auth\AppleIdToken;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

/**
 * Anmelden ohne Passwort (Link per Mail, Mit Apple) und die Warteliste.
 */
class PasswordlessSignInTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser')->andReturnUsing(function (User $u, string $title) {
            $this->pushes[] = [$u->email, $title];
        });
        $this->app->instance(WebPushService::class, $push);
    }

    private function linkFor(string $email): string
    {
        $this->post(route('login.link'), ['email' => $email])->assertSessionHas('linkSent', strtolower($email));

        $url = null;
        Notification::assertSentOnDemand(MagicLinkNotification::class, function ($n, $channels, AnonymousNotifiable $to) use ($email, &$url) {
            $url = $n->toMail($to)->actionUrl;

            return $to->routes['mail'] === strtolower($email);
        });

        return basename(parse_url($url, PHP_URL_PATH));
    }

    // ── Link per Mail ─────────────────────────────────────────────────

    public function test_a_new_address_lands_on_the_waitlist_and_admins_are_told(): void
    {
        User::factory()->create(['email' => 'admin@zone3.run', 'is_admin' => true]);

        $token = $this->linkFor('Neu.Laeufer@example.com');
        $this->assertDatabaseMissing('users', ['email' => 'neu.laeufer@example.com']);

        $this->get(route('login.link.show', $token))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Auth/MagicLink')->where('email', 'neu.laeufer@example.com'));

        $this->post(route('login.link.consume', $token))->assertRedirect(route('waitlist'));

        $user = User::where('email', 'neu.laeufer@example.com')->first();
        $this->assertNotNull($user->waitlisted_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('Neu Laeufer', $user->name);
        $this->assertAuthenticatedAs($user);
        $this->assertSame([['admin@zone3.run', 'Neue Anmeldung wartet']], $this->pushes);
    }

    public function test_the_link_works_once(): void
    {
        $token = $this->linkFor('einmal@example.com');

        $this->post(route('login.link.consume', $token));
        $this->post(route('logout'));

        $this->post(route('login.link.consume', $token))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->get(route('login.link.show', $token))->assertInertia(fn ($p) => $p->where('token', null));
    }

    public function test_an_existing_account_simply_signs_in(): void
    {
        $user = User::factory()->onboarded()->create(['email' => 'da@example.com']);

        $this->post(route('login.link.consume', $this->linkFor('da@example.com')))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
        $this->assertSame([], $this->pushes);
    }

    public function test_in_open_mode_a_new_account_is_in_right_away(): void
    {
        Access::setMode(Access::OPEN);

        $this->post(route('login.link.consume', $this->linkFor('offen@example.com')))->assertRedirect(route('dashboard'));

        $this->assertNull(User::where('email', 'offen@example.com')->value('waitlisted_at'));
    }

    // ── Warteliste ────────────────────────────────────────────────────

    public function test_a_waitlisted_account_sees_only_the_waitlist(): void
    {
        $user = User::factory()->onboarded()->create(['waitlisted_at' => now()]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('waitlist'));
        $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('waitlist'));
        $this->actingAs($user)->getJson(route('weather.today'))->assertForbidden();
        $this->actingAs($user)->get(route('waitlist'))->assertOk()->assertInertia(fn ($p) => $p->component('Waitlist'));
        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
    }

    public function test_an_approved_account_does_not_see_the_waitlist(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->get(route('waitlist'))->assertRedirect(route('dashboard'));
    }

    public function test_the_admin_approves_rejects_and_switches(): void
    {
        $admin  = User::factory()->onboarded()->create(['is_admin' => true]);
        $first  = User::factory()->create(['waitlisted_at' => now()]);
        $second = User::factory()->create(['waitlisted_at' => now()]);

        $this->actingAs($admin)->get(route('admin.invitations.index'))
            ->assertInertia(fn ($p) => $p->where('mode', 'waitlist')->has('waiting', 2));

        $this->actingAs($admin)->post(route('admin.waitlist.approve', $first))->assertRedirect();
        $this->assertNull($first->fresh()->waitlisted_at);
        Notification::assertSentTo($first, WaitlistApprovedNotification::class);
        $this->assertContains([$first->email, 'Du bist dabei'], $this->pushes);

        $this->actingAs($admin)->delete(route('admin.waitlist.reject', $second))->assertRedirect();
        $this->assertModelMissing($second);

        // Ein freigeschaltetes Konto lässt sich nicht über die Warteliste löschen.
        $this->actingAs($admin)->delete(route('admin.waitlist.reject', $first))->assertNotFound();

        $this->actingAs($admin)->post(route('admin.invitations.mode'), ['mode' => 'open']);
        $this->assertSame(Access::OPEN, Access::mode());
    }

    public function test_only_admins_reach_the_waitlist_controls(): void
    {
        $user    = User::factory()->onboarded()->create();
        $waiting = User::factory()->create(['waitlisted_at' => now()]);

        $this->actingAs($user)->post(route('admin.waitlist.approve', $waiting))->assertForbidden();
        $this->assertNotNull($waiting->fresh()->waitlisted_at);
    }

    // ── Mit Apple ─────────────────────────────────────────────────────

    private \OpenSSLAsymmetricKey $key;

    private function fakeApple(): void
    {
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $d = openssl_pkey_get_details($this->key)['rsa'];
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        Http::fake([AppleIdToken::KEYS_URL => Http::response(['keys' => [
            ['kty' => 'RSA', 'kid' => 'test-kid', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($d['n']), 'e' => $b64($d['e'])],
        ]])]);
    }

    private function idToken(array $claims): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'RS256', 'kid' => 'test-kid']));
        $body = $b64(json_encode($claims + [
            'iss' => AppleIdToken::ISSUER, 'aud' => 'run.zone3.app', 'exp' => time() + 600, 'iat' => time(),
            'sub' => '001234.abc', 'email' => 'apfel@privaterelay.appleid.com', 'email_verified' => 'true', 'is_private_email' => 'true',
        ]));
        openssl_sign("$head.$body", $sig, $this->key, OPENSSL_ALGO_SHA256);

        return "$head.$body." . $b64($sig);
    }

    private function nonce(): string
    {
        return $this->getJson(route('auth.apple.nonce'))->json('nonce');
    }

    public function test_the_app_signs_in_with_apple_and_creates_a_waiting_account(): void
    {
        $this->fakeApple();
        $nonce = $this->nonce();

        $this->postJson(route('auth.apple.native'), [
            'identity_token' => $this->idToken(['nonce' => $nonce]),
            'given_name' => 'Anna', 'family_name' => 'Apfel',
        ])->assertOk()->assertJson(['redirect' => route('waitlist')]);

        $user = User::where('apple_sub', '001234.abc')->first();
        $this->assertSame('Anna Apfel', $user->name);
        $this->assertSame('apfel@privaterelay.appleid.com', $user->email);
        $this->assertNotNull($user->waitlisted_at);
        $this->assertAuthenticatedAs($user);

        // Beim nächsten Mal: dasselbe Konto über `sub`, ohne Namen.
        $this->post(route('logout'));
        $this->postJson(route('auth.apple.native'), ['identity_token' => $this->idToken(['nonce' => $this->nonce()])])->assertOk();
        $this->assertSame(1, User::count());
    }

    public function test_apple_attaches_to_an_existing_account_with_the_same_address(): void
    {
        $this->fakeApple();
        $user = User::factory()->onboarded()->create(['email' => 'jan@example.com']);

        $this->postJson(route('auth.apple.native'), [
            'identity_token' => $this->idToken(['nonce' => $this->nonce(), 'email' => 'Jan@example.com', 'is_private_email' => 'false']),
        ])->assertOk()->assertJson(['redirect' => route('dashboard')]);

        $this->assertSame('001234.abc', $user->fresh()->apple_sub);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_token_with_a_wrong_nonce_audience_or_signature_is_refused(): void
    {
        $this->fakeApple();

        $this->nonce();
        $this->postJson(route('auth.apple.native'), ['identity_token' => $this->idToken(['nonce' => 'fremd'])])->assertStatus(422);

        $nonce = $this->nonce();
        $this->postJson(route('auth.apple.native'), ['identity_token' => $this->idToken(['nonce' => $nonce, 'aud' => 'com.other.app'])])->assertStatus(422);

        $nonce = $this->nonce();
        $forged = $this->idToken(['nonce' => $nonce]);
        [$h, , $s] = explode('.', $forged);
        $body = rtrim(strtr(base64_encode(json_encode(['iss' => AppleIdToken::ISSUER, 'aud' => 'run.zone3.app', 'exp' => time() + 600, 'sub' => 'boese', 'email' => 'x@y.de', 'nonce' => $nonce])), '+/', '-_'), '=');
        $this->postJson(route('auth.apple.native'), ['identity_token' => "$h.$body.$s"])->assertStatus(422);

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_the_web_flow_goes_through_apple_and_back(): void
    {
        config(['services.apple_signin.client_id' => 'run.zone3.signin']);
        $this->fakeApple();

        $location = $this->get(route('auth.apple'))->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('run.zone3.signin', $q['client_id']);
        $this->assertSame('form_post', $q['response_mode']);

        $this->post(route('auth.apple.callback'), [
            'state' => $q['state'],
            'id_token' => $this->idToken(['nonce' => $q['nonce'], 'aud' => 'run.zone3.signin']),
            'user' => json_encode(['name' => ['firstName' => 'Web', 'lastName' => 'Läuferin']]),
        ])->assertRedirect(route('waitlist'));

        $this->assertSame('Web Läuferin', User::where('apple_sub', '001234.abc')->value('name'));

        // Derselbe state ein zweites Mal: abgelaufen.
        $this->post(route('logout'));
        $this->post(route('auth.apple.callback'), ['state' => $q['state'], 'id_token' => 'x'])->assertRedirect(route('login'))->assertSessionHasErrors('apple');
    }

    public function test_without_a_services_id_there_is_no_web_flow(): void
    {
        config(['services.apple_signin.client_id' => null]);

        $this->get(route('auth.apple'))->assertNotFound();
        $this->get(route('login'))->assertInertia(fn ($p) => $p->where('appleWeb', false));
    }

    public function test_the_mail_link_opens_the_app(): void
    {
        $this->assertContains(['/' => '/login/link/*'], $this->getJson('/.well-known/apple-app-site-association')->json('applinks.details.0.components'));
    }
}
