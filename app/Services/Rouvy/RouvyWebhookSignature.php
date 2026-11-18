<?php

namespace App\Services\Rouvy;

use Throwable;

/**
 * ROUVYs Webhooks sind nach „Standard Webhooks“ signiert, Schema `v1a`
 * (Ed25519). Signiert ist `{webhook-id}.{webhook-timestamp}.{roher Body}`;
 * `webhook-signature` ist eine durch Leerzeichen getrennte Liste
 * `v1a,<base64>`. Die öffentlichen Schlüssel liefert `GET /webhooks/public-keys`
 * als `whpk_` + base64 SPKI-DER; ein Ed25519-SPKI ist 44 Bytes, der rohe
 * Schlüssel sind die letzten 32.
 *
 * Der Schlüssel gilt für **alle** ROUVY-Apps — eine gültige Signatur allein
 * beweist nicht, dass der Aufruf für Zone3 bestimmt ist. Darum prüft der
 * Controller zusätzlich `clientId`.
 */
class RouvyWebhookSignature
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly RouvyClient $client) {}

    public function verify(string $id, string $timestamp, string $signatures, string $body): bool
    {
        if ($id === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $message = "{$id}.{$timestamp}.{$body}";

        foreach ([false, true] as $fresh) {
            try {
                $keys = $this->client->webhookKeys($fresh);
            } catch (Throwable) {
                return false;
            }
            if (self::anyVerifies($message, $signatures, $keys)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $keys */
    public static function anyVerifies(string $message, string $signatures, array $keys): bool
    {
        $raw = array_filter(array_map([self::class, 'rawKey'], $keys));

        foreach (preg_split('/\s+/', trim($signatures)) as $entry) {
            [$scheme, $sig] = array_pad(explode(',', $entry, 2), 2, '');
            if ($scheme !== 'v1a') {
                continue;
            }
            $sig = base64_decode($sig, true);
            if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
                continue;
            }
            foreach ($raw as $key) {
                if (sodium_crypto_sign_verify_detached($sig, $message, $key)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function rawKey(string $whpk): ?string
    {
        $der = base64_decode(preg_replace('/^whpk_/', '', $whpk), true);
        if ($der === false || strlen($der) < SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        return substr($der, -SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
    }
}
