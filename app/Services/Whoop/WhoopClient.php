<?php

namespace App\Services\Whoop;

use App\Models\WhoopConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Transport zur WHOOP Developer API v2 (developer.whoop.com).
 *
 * - **OAuth2 mit Refresh-Token**, Client-ID und Secret im Body. Der
 *   Access-Token gilt eine Stunde. **Jede Erneuerung liefert einen neuen
 *   Refresh-Token und macht den alten ungültig** — erneuert wird darum unter
 *   einer Sperre pro Athlet und mit frisch gelesener Zeile, wie bei Garmin.
 * - Ohne Scope `offline` gibt es keinen Refresh-Token.
 * - Listen (`/v2/recovery`, `/v2/activity/sleep`, `/v2/activity/workout`)
 *   mit `start`/`end`, höchstens 25 je Seite, weiter über `nextToken`.
 * - Trennen: `DELETE /v2/user/access` widerruft den Zugang bei WHOOP.
 */
class WhoopClient
{
    public const SCOPES = 'offline read:recovery read:sleep read:workout read:profile';

    private const PAGE_LIMIT = 25;
    private const MAX_PAGES = 20;

    public function configured(): bool
    {
        return filled(config('services.whoop.client_id')) && filled(config('services.whoop.client_secret'));
    }

    private function api(): string
    {
        return rtrim((string) config('services.whoop.api_url'), '/');
    }

    public function authorizationUrl(string $state): string
    {
        return config('services.whoop.auth_url') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => config('services.whoop.client_id'),
            'redirect_uri'  => config('services.whoop.redirect'),
            'scope'         => self::SCOPES,
            'state'         => $state,
        ]);
    }

    /** @return array{access_token:string, refresh_token:?string, expires_in:int, scope:?string} */
    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => config('services.whoop.redirect'),
        ]);
    }

    /** Felder für `whoop_connections` aus einer Token-Antwort. */
    public static function tokenFields(array $token): array
    {
        return array_filter([
            'access_token'  => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at'    => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'scopes'        => $token['scope'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** @return array{user_id:int, first_name:?string} */
    public function profile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->acceptJson()->timeout(20)->get($this->api() . '/v2/user/profile/basic');
        $this->guard($response, 'Profil');

        $profile = (array) $response->json();
        if (empty($profile['user_id'])) {
            throw new WhoopException('WHOOP lieferte keine User-ID.');
        }

        return $profile;
    }

    /** Zugang bei WHOOP widerrufen. 401/404 = war schon weg. */
    public function revoke(WhoopConnection $connection): void
    {
        $response = Http::withToken($this->token($connection))->acceptJson()->timeout(20)->delete($this->api() . '/v2/user/access');

        if (! $response->successful() && ! in_array($response->status(), [401, 403, 404], true)) {
            throw new WhoopException('WHOOP-Widerruf: HTTP ' . $response->status(), $response->status());
        }
    }

    /** @return list<array> Workouts seit $since, neueste zuerst */
    public function workouts(WhoopConnection $connection, \DateTimeInterface $since): array
    {
        return $this->collection($connection, '/v2/activity/workout', $since);
    }

    /** @return list<array> */
    public function sleeps(WhoopConnection $connection, \DateTimeInterface $since): array
    {
        return $this->collection($connection, '/v2/activity/sleep', $since);
    }

    /** @return list<array> */
    public function recoveries(WhoopConnection $connection, \DateTimeInterface $since): array
    {
        return $this->collection($connection, '/v2/recovery', $since);
    }

    public function workout(WhoopConnection $connection, string $id): ?array
    {
        $response = Http::withToken($this->token($connection))->acceptJson()->timeout(30)
            ->get($this->api() . '/v2/activity/workout/' . rawurlencode($id));

        if ($response->status() === 404) {
            return null;
        }
        $this->guard($response, 'Workout');

        return (array) $response->json();
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @return list<array> */
    private function collection(WhoopConnection $connection, string $path, \DateTimeInterface $since): array
    {
        $records = [];
        $next    = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = Http::withToken($this->token($connection))->acceptJson()->timeout(30)
                ->get($this->api() . $path, array_filter([
                    'start'     => \Carbon\Carbon::instance($since)->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'limit'     => self::PAGE_LIMIT,
                    'nextToken' => $next,
                ]));
            $this->guard($response, $path);

            foreach ((array) $response->json('records') as $record) {
                if (is_array($record)) {
                    $records[] = $record;
                }
            }

            $next = $response->json('next_token') ?: $response->json('nextToken');
            if (! $next) {
                break;
            }
        }

        return $records;
    }

    /** Ein gültiger Access-Token; erneuert, wenn er in fünf Minuten abläuft. */
    public function token(WhoopConnection $connection): string
    {
        if ($connection->expires_at && $connection->expires_at->gt(now()->addMinutes(5))) {
            return $connection->access_token;
        }

        $this->refresh($connection);

        return $connection->access_token;
    }

    public function refresh(WhoopConnection $connection): void
    {
        Cache::lock('whoop-refresh:' . $connection->user_id, 30)->block(20, function () use ($connection) {
            $connection->refresh();

            // Ein anderer Prozess war schneller.
            if ($connection->expires_at && $connection->expires_at->gt(now()->addMinutes(5))) {
                return;
            }
            if (! $connection->refresh_token) {
                throw new WhoopException('WHOOP: kein Refresh-Token, bitte neu verbinden.', 401);
            }

            try {
                $token = $this->tokenRequest([
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $connection->refresh_token,
                    'scope'         => 'offline',
                ]);
            } catch (WhoopException $e) {
                $connection->update(['last_error' => Str::limit($e->getMessage(), 250), 'last_error_at' => now()]);
                throw $e;
            }

            $connection->update(self::tokenFields($token));
        });
    }

    private function tokenRequest(array $fields): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post((string) config('services.whoop.token_url'), $fields + [
                'client_id'     => config('services.whoop.client_id'),
                'client_secret' => config('services.whoop.client_secret'),
            ]);
        } catch (Throwable $e) {
            throw new WhoopException('WHOOP nicht erreichbar: ' . $e->getMessage());
        }

        $token = $response->json();
        if (! $response->successful() || empty($token['access_token'])) {
            throw new WhoopException('WHOOP hat den Token abgelehnt: HTTP ' . $response->status() . ' ' . Str::limit($response->body(), 200), $response->status());
        }

        return $token;
    }

    private function guard(Response $response, string $what): void
    {
        if (! $response->successful()) {
            throw new WhoopException("WHOOP {$what}: HTTP {$response->status()} " . Str::limit($response->body(), 200), $response->status());
        }
    }
}
