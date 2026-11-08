<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\ApnsClient;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Push aus der iOS-App: das Gerätetoken landet in push_subscriptions
 * (kind = apns) und jeder bestehende Versand über WebPushService erreicht
 * es über APNs.
 */
class NativePushTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);

        config([
            'services.apns.key_id'     => 'KEY1234567',
            'services.apns.team_id'    => 'Y7NR92XBVK',
            'services.apns.key'        => $pem,
            'services.apns.bundle_id'  => 'run.zone3.app',
            'services.apns.production' => true,
        ]);
        Cache::flush();
    }

    public function test_the_app_stores_its_device_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('push.native'), ['token' => strtoupper(self::TOKEN)])
            ->assertOk();

        $sub = PushSubscription::sole();
        $this->assertSame(PushSubscription::KIND_APNS, $sub->kind);
        $this->assertSame(self::TOKEN, $sub->endpoint);
        $this->assertNull($sub->public_key);
        $this->assertTrue($user->fresh()->push_notifications_enabled);
    }

    public function test_a_token_moves_with_the_account_instead_of_doubling(): void
    {
        $first  = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->postJson(route('push.native'), ['token' => self::TOKEN])->assertOk();
        $this->actingAs($second)->postJson(route('push.native'), ['token' => self::TOKEN])->assertOk();

        $this->assertSame($second->id, PushSubscription::sole()->user_id);
    }

    public function test_a_token_that_is_not_hex_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('push.native'), ['token' => 'https://evil.example/push'])
            ->assertUnprocessable();
    }

    public function test_a_push_reaches_the_app_through_apns(): void
    {
        Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

        $user = User::factory()->create();
        PushSubscription::create(['user_id' => $user->id, 'kind' => 'apns', 'endpoint' => self::TOKEN]);

        app(WebPushService::class)->sendToUser($user, 'Deine Woche steht', 'Sieben Einheiten', '/plan');

        Http::assertSent(function (Request $request) {
            $jwt = substr($request->header('authorization')[0], strlen('bearer '));
            [$header] = explode('.', $jwt);

            return $request->url() === 'https://api.push.apple.com/3/device/' . self::TOKEN
                && $request->header('apns-topic')[0] === 'run.zone3.app'
                && $request->header('apns-push-type')[0] === 'alert'
                && json_decode(base64_decode(strtr($header, '-_', '+/')), true)['kid'] === 'KEY1234567'
                && $request['aps']['alert']['title'] === 'Deine Woche steht'
                && $request['url'] === '/plan';
        });
        $this->assertSame(1, PushSubscription::count());
    }

    public function test_a_token_apple_no_longer_knows_is_removed(): void
    {
        Http::fake(['api.push.apple.com/*' => Http::response(['reason' => 'Unregistered'], 410)]);

        $user = User::factory()->create();
        PushSubscription::create(['user_id' => $user->id, 'kind' => 'apns', 'endpoint' => self::TOKEN]);

        app(WebPushService::class)->sendToUser($user, 'Titel', 'Text');

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_any_other_rejection_keeps_the_token(): void
    {
        Http::fake(['api.push.apple.com/*' => Http::response(['reason' => 'TooManyRequests'], 429)]);

        $user = User::factory()->create();
        PushSubscription::create(['user_id' => $user->id, 'kind' => 'apns', 'endpoint' => self::TOKEN]);

        app(WebPushService::class)->sendToUser($user, 'Titel', 'Text');

        $this->assertSame(1, PushSubscription::count());
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        Http::fake();
        config(['services.apns.key' => null]);

        $user = User::factory()->create();
        PushSubscription::create(['user_id' => $user->id, 'kind' => 'apns', 'endpoint' => self::TOKEN]);

        app(WebPushService::class)->sendToUser($user, 'Titel', 'Text');

        Http::assertNothingSent();
        $this->assertSame(1, PushSubscription::count());
    }

    /** Die Signatur muss sich mit dem öffentlichen Schlüssel prüfen lassen. */
    public function test_the_jwt_signature_verifies(): void
    {
        Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

        $user = User::factory()->create();
        PushSubscription::create(['user_id' => $user->id, 'kind' => 'apns', 'endpoint' => self::TOKEN]);
        app(ApnsClient::class)->send(self::TOKEN, 'T', 'B');

        $jwt = null;
        Http::assertSent(function (Request $r) use (&$jwt) {
            $jwt = substr($r->header('authorization')[0], strlen('bearer '));
            return true;
        });

        [$h, $c, $sig] = explode('.', $jwt);
        $raw = base64_decode(strtr($sig, '-_', '+/') . str_repeat('=', (4 - strlen($sig) % 4) % 4));
        $this->assertSame(64, strlen($raw));

        // raw r||s zurück nach DER, dann mit OpenSSL prüfen
        $int = fn (string $v) => (function ($v) {
            $v = ltrim($v, "\x00");
            if (ord($v[0]) & 0x80) $v = "\x00" . $v;
            return "\x02" . chr(strlen($v)) . $v;
        })($v);
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        $der = "\x30" . chr(strlen($seq)) . $seq;

        $public = openssl_pkey_get_details(openssl_pkey_get_private(config('services.apns.key')))['key'];
        $this->assertSame(1, openssl_verify("{$h}.{$c}", $der, $public, OPENSSL_ALGO_SHA256));
    }
}
