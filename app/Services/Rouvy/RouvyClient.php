<?php

namespace App\Services\Rouvy;

use App\Models\RouvyConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Transport zur ROUVY Public API (api.rouvy.com/docs, OpenAPI 1.5.0, Kopie in
 * den Projektdateien unter rouvy-antrag/).
 *
 * - **OAuth2 Authorization Code ohne PKCE.** Der Rückweg trägt nicht die
 *   üblichen Namen: `response=success|error`, `code`, `state`, bei Fehler
 *   `reason`. Access-Token 1 h, ein Refresh **kann** einen neuen
 *   Refresh-Token liefern und macht den alten dann ungültig → Sperre pro
 *   Athlet wie bei Garmin und WHOOP.
 * - Scopes: `profile:read` (nur für die ROUVY-User-ID, die der Webhook als
 *   `ownerId` nennt), `activities:read`, `workouts:write`. Kein `events:read`.
 * - Workouts: `POST /workouts/push` (multipart, ZWO-Datei + `plannedAt`; nur
 *   das Datum zählt) → `workoutId`; `DELETE /workouts/{id}`. **Kein Ändern.**
 * - Aktivitäten: `GET /activities`, `GET /activities/{id}`, `…/fit`.
 * - Kontingent 30 Aufrufe/min je App in der Entwicklung, 60 in Produktion.
 */
class RouvyClient
{
    public const SCOPES = 'profile:read activities:read workouts:write';

    public function configured(): bool
    {
        return filled(config('services.rouvy.client_id')) && filled(config('services.rouvy.client_secret'));
    }

    private function api(): string
    {
        return rtrim((string) config('services.rouvy.api_url'), '/');
    }

    public function authorizationUrl(string $state): string
    {
        return $this->api() . '/oauth/authorize?' . http_build_query([
            'client_id'     => config('services.rouvy.client_id'),
            'redirect_uri'  => config('services.rouvy.redirect'),
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'state'         => $state,
        ]);
    }

    /** @return array{access_token:string, refresh_token:?string, expires_in:int} */
    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => config('services.rouvy.redirect'),
        ]);
    }

    public static function tokenFields(array $token): array
    {
        return array_filter([
            'access_token'  => $token['access_token'],
            // Ohne neuen Refresh-Token bleibt der alte gültig.
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at'    => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        ], fn ($v) => $v !== null);
    }

    /** Die ROUVY-User-ID zu einem frischen Token. Mehr vom Profil braucht Zone3 nicht. */
    public function userId(string $accessToken): string
    {
        $response = $this->http()->withToken($accessToken)->get($this->api() . '/me');
        $this->guard($response, 'Profil');

        $id = (string) ($response->json('user.userId') ?? '');
        if ($id === '') {
            throw new RouvyException('ROUVY-Profil ohne userId');
        }

        return $id;
    }

    /** Refresh-Token bei ROUVY widerrufen. ROUVY antwortet immer 200. */
    public function revoke(RouvyConnection $connection): void
    {
        if (! $connection->refresh_token) {
            return;
        }

        $this->http()->asForm()->post($this->api() . '/oauth/revoke', [
            'client_id'       => config('services.rouvy.client_id'),
            'client_secret'   => config('services.rouvy.client_secret'),
            'token'           => $connection->refresh_token,
            'token_type_hint' => 'refresh_token',
        ]);
    }

    /** Ein Workout (ZWO) für einen Tag in die Bibliothek legen. @return string workoutId */
    public function pushWorkout(RouvyConnection $connection, string $date, string $filename, string $zwo): string
    {
        $response = $this->authed($connection)
            ->attach('file', $zwo, $filename, ['Content-Type' => 'application/octet-stream'])
            ->post($this->api() . '/workouts/push', ['plannedAt' => $date . 'T12:00:00Z']);
        $this->guard($response, 'Workout senden');

        $id = $response->json('workoutId');
        if ($id === null || $id === '') {
            throw new RouvyException('ROUVY: Workout ohne workoutId angenommen ' . Str::limit($response->body(), 200));
        }

        // Die Spezifikation nennt eine Zahl (double): 12345.0 → "12345".
        return is_numeric($id) ? (string) (int) $id : (string) $id;
    }

    /** 404 = war schon weg. */
    public function deleteWorkout(RouvyConnection $connection, string $workoutId): void
    {
        $response = $this->authed($connection)->delete($this->api() . '/workouts/' . rawurlencode($workoutId));

        if ($response->status() !== 404) {
            $this->guard($response, 'Workout löschen');
        }
    }

    public function activity(RouvyConnection $connection, string $id): ?array
    {
        $response = $this->authed($connection)->get($this->api() . '/activities/' . rawurlencode($id));

        if ($response->status() === 404) {
            return null;
        }
        $this->guard($response, 'Aktivität');

        $activity = $response->json('activity');

        return is_array($activity) ? $activity : null;
    }

    /** @return list<array> Fahrten ab dem Zeitpunkt, höchstens 100. */
    public function activities(RouvyConnection $connection, \DateTimeInterface $from): array
    {
        $response = $this->authed($connection)->get($this->api() . '/activities', [
            'fromDateTime' => gmdate('Y-m-d\TH:i:s\Z', $from->getTimestamp()),
            'limit'        => 100,
        ]);
        $this->guard($response, 'Aktivitäten');

        return array_values(array_filter((array) $response->json('activities'), 'is_array'));
    }

    /** Die FIT-Datei einer Fahrt. null = keine Datei. */
    public function activityFile(RouvyConnection $connection, string $id): ?string
    {
        $response = $this->authed($connection)->timeout(60)->get($this->api() . '/activities/' . rawurlencode($id) . '/fit');

        if ($response->status() === 404) {
            return null;
        }
        $this->guard($response, 'FIT-Datei');

        return $response->body() !== '' ? $response->body() : null;
    }

    /** Die öffentlichen Schlüssel für die Webhook-Signatur, eine Stunde gemerkt. @return list<string> */
    public function webhookKeys(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('rouvy:webhook-keys');
        }

        return Cache::remember('rouvy:webhook-keys', now()->addHour(), function () {
            $response = $this->http()->get($this->api() . '/webhooks/public-keys');
            $this->guard($response, 'Webhook-Schlüssel');

            return collect((array) $response->json('keys'))
                ->filter(fn ($k) => is_array($k) && ($k['alg'] ?? null) === 'ed25519' && is_string($k['key'] ?? null))
                ->pluck('key')->values()->all();
        });
    }

    // ────────────────────────────────────────────────────────────────────────

    /** Ein gültiger Access-Token; erneuert, wenn er in fünf Minuten abläuft. */
    public function token(RouvyConnection $connection): string
    {
        if ($connection->expires_at && $connection->expires_at->gt(now()->addMinutes(5))) {
            return $connection->access_token;
        }

        $this->refresh($connection);

        return $connection->access_token;
    }

    public function refresh(RouvyConnection $connection): void
    {
        Cache::lock('rouvy-refresh:' . $connection->user_id, 30)->block(20, function () use ($connection) {
            $connection->refresh();

            // Ein anderer Prozess war schneller.
            if ($connection->expires_at && $connection->expires_at->gt(now()->addMinutes(5))) {
                return;
            }
            if (! $connection->refresh_token) {
                throw new RouvyException('ROUVY: kein Refresh-Token, bitte neu verbinden.', 401);
            }

            try {
                $token = $this->tokenRequest([
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $connection->refresh_token,
                ]);
            } catch (RouvyException $e) {
                $connection->update(['last_error' => Str::limit($e->getMessage(), 250), 'last_error_at' => now()]);
                throw $e;
            }

            $connection->update(self::tokenFields($token));
        });
    }

    private function tokenRequest(array $fields): array
    {
        try {
            $response = $this->http()->asForm()->post($this->api() . '/oauth/token', $fields + [
                'client_id'     => config('services.rouvy.client_id'),
                'client_secret' => config('services.rouvy.client_secret'),
            ]);
        } catch (Throwable $e) {
            throw new RouvyException('ROUVY nicht erreichbar: ' . $e->getMessage());
        }

        $token = $response->json();
        if (! $response->successful() || empty($token['access_token'])) {
            // 400 vom Token-Endpunkt heißt invalid_grant: der Zugang ist tot.
            $code = $response->status() === 400 ? 401 : $response->status();
            throw new RouvyException('ROUVY hat den Token abgelehnt: HTTP ' . $response->status() . ' ' . Str::limit($response->body(), 200), $code);
        }

        return $token;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->withUserAgent('Zone3/1.0 (+https://zone3.run)');
    }

    private function authed(RouvyConnection $connection): PendingRequest
    {
        return $this->http()->withToken($this->token($connection));
    }

    private function guard(Response $response, string $what): void
    {
        if (! $response->successful()) {
            throw new RouvyException("ROUVY {$what}: HTTP {$response->status()} " . Str::limit($response->body(), 200), $response->status());
        }
    }
}
