<?php

namespace App\Services\Intervals;

use App\Models\IntervalsConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Transport zur intervals.icu-API (forum.intervals.icu, „API integration
 * cookbook“ und „OAuth support“).
 *
 * - **OAuth2 mit PKCE (S256)**, Scopes durch Komma getrennt. Der Code muss
 *   binnen zwei Minuten getauscht werden. Die Antwort trägt `access_token`,
 *   `scope` und `athlete.id` (String). **Kein Refresh-Token, kein Ablauf** —
 *   ein 401/403 heißt: der Athlet hat die App bei intervals.icu entzogen.
 * - Zone3 fragt nur `CALENDAR:WRITE` (Radeinheiten nach Zwift) und
 *   `ACTIVITY:READ` (Fahrten zurück) an. Kein Wellness.
 * - Kalender: `POST /athlete/{id}/events/bulk?upsert=true` mit `external_id`
 *   als unserem Schlüssel; Löschen über `PUT …/events/bulk-delete`.
 * - Strava-Aktivitäten liefert die API nicht (intervals' Vertrag mit Strava).
 * - Kontingent für OAuth-Apps: standardmäßig 100 Aufrufe je Athlet und Tag.
 * - Cloudflare vor intervals.icu weist manche Standard-User-Agents ab,
 *   darum ein eigener.
 */
class IntervalsClient
{
    public const SCOPES = 'CALENDAR:WRITE,ACTIVITY:READ';

    public function configured(): bool
    {
        return filled(config('services.intervals.client_id')) && filled(config('services.intervals.client_secret'));
    }

    private function api(): string
    {
        return rtrim((string) config('services.intervals.api_url'), '/');
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return config('services.intervals.auth_url') . '?' . http_build_query([
            'client_id'             => config('services.intervals.client_id'),
            'redirect_uri'          => config('services.intervals.redirect'),
            'scope'                 => self::SCOPES,
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @return array{access_token:string, scope:?string, athlete_id:string} */
    public function exchangeCode(string $code, string $verifier): array
    {
        try {
            $response = $this->http()->asForm()->post((string) config('services.intervals.token_url'), [
                'client_id'     => config('services.intervals.client_id'),
                'client_secret' => config('services.intervals.client_secret'),
                'code'          => $code,
                'code_verifier' => $verifier,
            ]);
        } catch (Throwable $e) {
            throw new IntervalsException('intervals.icu nicht erreichbar: ' . $e->getMessage());
        }

        $token = (array) $response->json();
        $athlete = (string) ($token['athlete']['id'] ?? $token['athlete_id'] ?? '');

        if (! $response->successful() || empty($token['access_token']) || $athlete === '') {
            throw new IntervalsException('intervals.icu hat den Token abgelehnt: HTTP ' . $response->status() . ' ' . Str::limit($response->body(), 200), $response->status());
        }

        return [
            'access_token' => (string) $token['access_token'],
            'scope'        => isset($token['scope']) ? (string) $token['scope'] : null,
            'athlete_id'   => $athlete,
        ];
    }

    /** Zugang bei intervals.icu widerrufen; stoppt auch die Webhooks. 401/403/404 = war schon weg. */
    public function revoke(IntervalsConnection $connection): void
    {
        $response = $this->authed($connection)->delete($this->api() . '/disconnect-app');

        if (! $response->successful() && ! in_array($response->status(), [401, 403, 404], true)) {
            throw new IntervalsException('intervals.icu-Widerruf: HTTP ' . $response->status(), $response->status());
        }
    }

    /**
     * Events anlegen oder über `external_id` ersetzen.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>  die Events, wie intervals.icu sie gespeichert hat
     */
    public function upsertEvents(IntervalsConnection $connection, array $events): array
    {
        if ($events === []) {
            return [];
        }

        $response = $this->authed($connection)
            ->post($this->athlete($connection) . '/events/bulk?upsert=true', $events);
        $this->guard($response, 'Kalender schreiben');

        return array_values(array_filter((array) $response->json(), 'is_array'));
    }

    /** @param list<string> $externalIds Fehlende ignoriert intervals.icu. */
    public function deleteEvents(IntervalsConnection $connection, array $externalIds): void
    {
        if ($externalIds === []) {
            return;
        }

        $response = $this->authed($connection)->put(
            $this->athlete($connection) . '/events/bulk-delete',
            array_map(fn ($id) => ['external_id' => $id], array_values($externalIds)),
        );
        $this->guard($response, 'Kalender löschen');
    }

    public function activity(IntervalsConnection $connection, string $id): ?array
    {
        $response = $this->authed($connection)->get($this->api() . '/activity/' . rawurlencode($id));

        if ($response->status() === 404) {
            return null;
        }
        $this->guard($response, 'Aktivität');

        return (array) $response->json();
    }

    /** @return list<array> Aktivitäten zwischen den Tagen (lokale Daten). */
    public function activities(IntervalsConnection $connection, \DateTimeInterface $oldest, \DateTimeInterface $newest): array
    {
        $response = $this->authed($connection)->get($this->athlete($connection) . '/activities', [
            'oldest' => $oldest->format('Y-m-d'),
            'newest' => $newest->format('Y-m-d'),
        ]);
        $this->guard($response, 'Aktivitäten');

        return array_values(array_filter((array) $response->json(), 'is_array'));
    }

    /** Die Originaldatei (FIT), von intervals.icu gzip-gepackt. null = keine Datei. */
    public function activityFile(IntervalsConnection $connection, string $id): ?string
    {
        $response = $this->authed($connection)->timeout(60)->get($this->api() . '/activity/' . rawurlencode($id) . '/file');

        if (in_array($response->status(), [404, 422], true)) {
            return null;
        }
        $this->guard($response, 'Datei');

        $body = $response->body();
        if (str_starts_with($body, "\x1f\x8b")) {
            $unpacked = @gzdecode($body);
            $body = $unpacked === false ? '' : $unpacked;
        }

        return $body !== '' ? $body : null;
    }

    // ────────────────────────────────────────────────────────────────────────

    private function athlete(IntervalsConnection $connection): string
    {
        return $this->api() . '/athlete/' . rawurlencode($connection->athlete_id);
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->withUserAgent('Zone3/1.0 (+https://zone3.run)');
    }

    private function authed(IntervalsConnection $connection): PendingRequest
    {
        return $this->http()->withToken($connection->access_token);
    }

    private function guard(Response $response, string $what): void
    {
        if (! $response->successful()) {
            throw new IntervalsException("intervals.icu {$what}: HTTP {$response->status()} " . Str::limit($response->body(), 200), $response->status());
        }
    }
}
