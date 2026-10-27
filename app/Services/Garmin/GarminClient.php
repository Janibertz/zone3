<?php

namespace App\Services\Garmin;

use App\Models\GarminConnection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Transport zum Garmin Connect Developer Program — OAuth2 mit PKCE.
 *
 * Zwei Eigenheiten tragen hier das Gewicht:
 *
 * - **Jede Erneuerung liefert einen neuen Refresh-Token**, der alte ist danach
 *   verbraucht. Erneuern zwei Prozesse gleichzeitig (Tagesjob und Webhook),
 *   benutzt einer einen verbrauchten Token, und die Verbindung ist tot. Darum
 *   laeuft jede Erneuerung unter einer Sperre pro Athlet und liest die Zeile
 *   danach frisch.
 * - **Ein Access-Token haelt rund 24 Stunden.** Garmin empfiehlt, ihn etwa
 *   zehn Minuten vor Ablauf zu erneuern; `accessToken()` tut das von selbst.
 */
class GarminClient
{
    /** So lange vor Ablauf wird erneuert (Empfehlung von Garmin: 600 s). */
    public const REFRESH_MARGIN_SECONDS = 600;

    public function configured(): bool
    {
        return config('services.garmin.client_id') && config('services.garmin.client_secret');
    }

    /** Ein zufaelliges Geheimnis, das nur in der Session des Athleten liegt. */
    public function makeVerifier(): string
    {
        return Str::random(64);
    }

    /** S256: base64url(sha256(verifier)) ohne Polsterung. */
    public function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function authorizationUrl(string $state, string $verifier): string
    {
        return config('services.garmin.authorize_url') . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => config('services.garmin.client_id'),
            'code_challenge'        => $this->challengeFor($verifier),
            'code_challenge_method' => 'S256',
            'redirect_uri'          => config('services.garmin.redirect'),
            'state'                 => $state,
        ]);
    }

    /**
     * Den Einmal-Code gegen Tokens tauschen.
     *
     * @return array{access_token:string, refresh_token:string, expires_in:int, refresh_token_expires_in:?int}
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        return $this->tokenRequest([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => config('services.garmin.redirect'),
        ]);
    }

    /** Die Felder einer Token-Antwort, wie `garmin_connections` sie speichert. */
    public function tokenFields(array $token): array
    {
        return [
            'access_token'             => $token['access_token'],
            'refresh_token'            => $token['refresh_token'],
            'access_token_expires_at'  => now()->addSeconds((int) ($token['expires_in'] ?? 0)),
            'refresh_token_expires_at' => isset($token['refresh_token_expires_in'])
                ? now()->addSeconds((int) $token['refresh_token_expires_in'])
                : null,
        ];
    }

    /** Ein gueltiger Access-Token; erneuert ihn, wenn er bald ablaeuft. */
    public function accessToken(GarminConnection $connection): string
    {
        if ($this->expiresSoon($connection)) {
            $this->refresh($connection);
        }

        return $connection->access_token;
    }

    /**
     * Die Tokens erneuern — unter Sperre, mit frisch gelesener Zeile.
     *
     * Hat ein anderer Prozess waehrend des Wartens schon erneuert, ist der
     * Token danach nicht mehr knapp, und es wird nichts mehr gesendet.
     * Ein Fehlschlag wird an der Verbindung vermerkt und weitergeworfen.
     */
    public function refresh(GarminConnection $connection, bool $force = false): void
    {
        Cache::lock('garmin-refresh:' . $connection->user_id, 30)->block(20, function () use ($connection, $force) {
            $connection->refresh();

            if (! $force && ! $this->expiresSoon($connection)) {
                return;
            }

            try {
                $token = $this->tokenRequest([
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $connection->refresh_token,
                ]);
            } catch (RuntimeException $e) {
                $connection->update([
                    'refresh_error'    => Str::limit($e->getMessage(), 250),
                    'refresh_error_at' => now(),
                ]);

                throw $e;
            }

            $connection->update($this->tokenFields($token) + [
                'last_refreshed_at' => now(),
                'refresh_error'     => null,
                'refresh_error_at'  => null,
            ]);
        });
    }

    public function userId(string $accessToken): string
    {
        $id = $this->api($accessToken)->get('/wellness-api/rest/user/id')->throw()->json('userId');

        if (! $id) {
            throw new RuntimeException('Garmin lieferte keine User-ID.');
        }

        return (string) $id;
    }

    /** @return list<string> */
    public function permissions(string $accessToken): array
    {
        return array_values((array) $this->api($accessToken)->get('/wellness-api/rest/user/permissions')->throw()->json());
    }

    /**
     * Die Freigaben neu abfragen — der Athlet kann sie in Garmin Connect
     * jederzeit aendern. Gibt die Antwort zurueck, damit ein Aufrufer an
     * einem 401 erkennen kann, dass die Verbindung nicht mehr besteht.
     */
    public function permissionsResponse(GarminConnection $connection): Response
    {
        return $this->api($this->accessToken($connection))->get('/wellness-api/rest/user/permissions');
    }

    /** Die Registrierung bei Garmin aufheben. Ein Fehler wirft. */
    public function deregister(GarminConnection $connection): void
    {
        $this->api($this->accessToken($connection))->delete('/wellness-api/rest/user/registration')->throw();
    }

    private function expiresSoon(GarminConnection $connection): bool
    {
        return ! $connection->access_token_expires_at
            || $connection->access_token_expires_at->lte(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));
    }

    private function api(string $accessToken)
    {
        return Http::baseUrl(config('services.garmin.api_url'))
            ->withToken($accessToken)
            ->acceptJson()
            ->timeout(15);
    }

    private function tokenRequest(array $params): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post(config('services.garmin.token_url'), $params + [
                'client_id'     => config('services.garmin.client_id'),
                'client_secret' => config('services.garmin.client_secret'),
            ])->throw();
        } catch (RequestException $e) {
            throw new RuntimeException('Garmin-Token-Anfrage abgelehnt (HTTP ' . $e->response->status() . ')', 0, $e);
        }

        $json = $response->json() ?: [];
        if (empty($json['access_token']) || empty($json['refresh_token'])) {
            throw new RuntimeException('Garmin-Token-Antwort unvollstaendig.');
        }

        return $json;
    }
}
