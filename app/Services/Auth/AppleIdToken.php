<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Prüft das id_token von „Mit Apple anmelden“ (RS256, Schlüssel von
 * appleid.apple.com/auth/keys).
 *
 * Kein Paket: es braucht nur die Signatur und fünf Claims. Die Schlüssel
 * werden einen Tag gemerkt; ein unbekannter `kid` (Apple rotiert) lädt
 * sie einmal neu.
 */
class AppleIdToken
{
    public const ISSUER = 'https://appleid.apple.com';
    public const KEYS_URL = 'https://appleid.apple.com/auth/keys';
    private const CACHE_KEY = 'apple-signin:jwks';

    /**
     * @param  list<string>  $audiences
     * @return array{sub: string, email: ?string, email_verified: bool, is_private_email: bool}
     */
    public function verify(string $jwt, array $audiences, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Kein gültiges Token.');
        }
        [$h, $p, $s] = $parts;

        $header = json_decode(self::b64($h), true);
        $claims = json_decode(self::b64($p), true);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256') {
            throw new RuntimeException('Kein gültiges Token.');
        }

        $pem = $this->key((string) ($header['kid'] ?? ''));
        if (openssl_verify("$h.$p", self::b64($s), $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Signatur stimmt nicht.');
        }

        if (($claims['iss'] ?? null) !== self::ISSUER) {
            throw new RuntimeException('Falscher Aussteller.');
        }
        $aud = (array) ($claims['aud'] ?? []);
        if (! array_intersect($aud, array_filter($audiences))) {
            throw new RuntimeException('Token gilt nicht für Zone3.');
        }
        if ((int) ($claims['exp'] ?? 0) < time() - 60) {
            throw new RuntimeException('Token abgelaufen.');
        }
        if ($nonce === '' || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('Nonce stimmt nicht.');
        }
        if (empty($claims['sub'])) {
            throw new RuntimeException('Token ohne Nutzer.');
        }

        // Apple schickt Wahrheitswerte mal als bool, mal als "true".
        $bool = fn ($v) => $v === true || $v === 'true';

        return [
            'sub'              => (string) $claims['sub'],
            'email'            => isset($claims['email']) ? strtolower((string) $claims['email']) : null,
            'email_verified'   => $bool($claims['email_verified'] ?? false),
            'is_private_email' => $bool($claims['is_private_email'] ?? false),
        ];
    }

    private function key(string $kid): string
    {
        $keys = Cache::get(self::CACHE_KEY);
        if (! is_array($keys) || ! isset($keys[$kid])) {
            $keys = $this->fetchKeys();
            Cache::put(self::CACHE_KEY, $keys, now()->addDay());
        }
        if (! isset($keys[$kid])) {
            throw new RuntimeException('Unbekannter Schlüssel.');
        }

        return $keys[$kid];
    }

    /** @return array<string, string> kid → PEM */
    private function fetchKeys(): array
    {
        $json = Http::timeout(10)->get(self::KEYS_URL)->throw()->json('keys', []);
        $out = [];
        foreach ($json as $jwk) {
            if (($jwk['kty'] ?? null) === 'RSA' && isset($jwk['kid'], $jwk['n'], $jwk['e'])) {
                $out[$jwk['kid']] = self::pem(self::b64($jwk['n']), self::b64($jwk['e']));
            }
        }

        return $out;
    }

    /** RSA-Schlüssel (Modulus, Exponent) als SubjectPublicKeyInfo im PEM-Format. */
    public static function pem(string $n, string $e): string
    {
        $int = function (string $b): string {
            $b = ltrim($b, "\x00");
            if ($b === '' || ord($b[0]) > 0x7F) {
                $b = "\x00" . $b; // positive Zahl
            }

            return "\x02" . self::len($b);
        };
        $rsa = self::seq($int($n) . $int($e));
        $algo = self::seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $spki = self::seq($algo . "\x03" . self::len("\x00" . $rsa));

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function seq(string $body): string
    {
        return "\x30" . self::len($body);
    }

    /** DER-Länge + Inhalt. */
    private static function len(string $body): string
    {
        $l = strlen($body);
        if ($l < 0x80) {
            return chr($l) . $body;
        }
        $bytes = ltrim(pack('N', $l), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes . $body;
    }

    public static function b64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
