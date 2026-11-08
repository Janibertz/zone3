<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Push an die iOS-App über Apples APNs (HTTP/2, Token-Anmeldung mit dem
 * .p8-Schlüssel). Web-Push kennt WKWebView nicht, darum dieser zweite Weg.
 *
 * TestFlight- und App-Store-Builds hängen am Produktions-Gateway; nur ein
 * aus Xcode installierter Build am Sandbox-Gateway (APNS_PRODUCTION=false).
 */
class ApnsClient
{
    public const SENT  = 'sent';
    public const GONE  = 'gone';   // Token ungültig oder App gelöscht → Zeile weg
    public const ERROR = 'error';

    /** Apple nimmt ein Token 20–60 Minuten an; neuer als 20 min darf es nicht wechseln. */
    private const JWT_TTL_SECONDS = 50 * 60;

    /** Gründe, bei denen das Token nie wieder funktioniert. */
    private const GONE_REASONS = ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'];

    public function configured(): bool
    {
        return filled(config('services.apns.key_id'))
            && filled(config('services.apns.team_id'))
            && filled(config('services.apns.key'));
    }

    public function send(string $deviceToken, string $title, string $body, string $url = '/dashboard'): string
    {
        if (! $this->configured()) {
            return self::ERROR;
        }

        $host = config('services.apns.production', true)
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';

        try {
            $response = Http::withOptions(['version' => 2.0])
                ->timeout(10)
                ->withHeaders([
                    'authorization'  => 'bearer ' . $this->jwt(),
                    'apns-topic'     => config('services.apns.bundle_id'),
                    'apns-push-type' => 'alert',
                    'apns-priority'  => '10',
                ])
                ->post("{$host}/3/device/{$deviceToken}", [
                    'aps' => [
                        'alert' => ['title' => $title, 'body' => $body],
                        'sound' => 'default',
                    ],
                    'url' => $url,
                ]);
        } catch (\Throwable $e) {
            Log::warning('APNs: Versand fehlgeschlagen', ['error' => $e->getMessage()]);
            return self::ERROR;
        }

        if ($response->successful()) {
            return self::SENT;
        }

        $reason = $response->json('reason');

        if ($response->status() === 410 || in_array($reason, self::GONE_REASONS, true)) {
            return self::GONE;
        }

        if ($reason === 'ExpiredProviderToken' || $reason === 'InvalidProviderToken') {
            Cache::forget($this->cacheKey());
        }

        Log::warning('APNs: abgelehnt', ['status' => $response->status(), 'reason' => $reason]);

        return self::ERROR;
    }

    private function cacheKey(): string
    {
        return 'apns:jwt:' . config('services.apns.key_id');
    }

    private function jwt(): string
    {
        return Cache::remember($this->cacheKey(), self::JWT_TTL_SECONDS, function () {
            $header = $this->base64url(json_encode(['alg' => 'ES256', 'kid' => config('services.apns.key_id')]));
            $claims = $this->base64url(json_encode(['iss' => config('services.apns.team_id'), 'iat' => time()]));
            $input  = "{$header}.{$claims}";

            $key = openssl_pkey_get_private($this->pem());
            if ($key === false || ! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('APNs: APNS_KEY ist kein gültiger .p8-Schlüssel.');
            }

            return $input . '.' . $this->base64url(self::derToRaw($der));
        });
    }

    /**
     * Coolify speichert mehrzeilige Werte manchmal mit "\n" statt Umbruch,
     * und manche kopieren nur den Base64-Teil. Beides wird hier ein PEM.
     */
    private function pem(): string
    {
        $key = trim(str_replace('\n', "\n", (string) config('services.apns.key')));

        if (! str_contains($key, '-----BEGIN')) {
            $key = "-----BEGIN PRIVATE KEY-----\n"
                . chunk_split(preg_replace('/\s+/', '', $key), 64, "\n")
                . "-----END PRIVATE KEY-----";
        }

        return $key;
    }

    /**
     * OpenSSL liefert die ECDSA-Signatur als DER-Sequenz (r, s); ein JWT
     * will r und s als je 32 Byte hintereinander.
     */
    public static function derToRaw(string $der): string
    {
        $offset = 2;                                   // SEQUENCE + Länge
        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7f;            // lange Längenangabe
        }

        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len   = ord($der[$offset + 1]);           // INTEGER + Länge
            $value = substr($der, $offset + 2, $len);
            $parts[] = str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $parts[0] . $parts[1];
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
