<?php

namespace App\Services\Coros;

use App\Models\CorosConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Transport zu COROS MCP: OAuth2 mit PKCE und JSON-RPC ueber HTTP.
 *
 * Am 05.11.2026 mit Jans COROS-Konto nachgemessen (Antworten in
 * `coros-antrag/mcp-probelauf/` der Projektdateien):
 *
 * - **Kein Antrag, keine Client-ID von COROS.** Der Server bietet dynamische
 *   Registrierung (`/connect/register`). Zone3 registriert sich einmal und
 *   merkt sich die ID; `COROS_CLIENT_ID` uebersteuert das. Den Device-Flow
 *   streicht COROS bei der Registrierung still — es bleibt der normale
 *   Rueckweg ueber `/coros/callback`.
 * - **Access-Token 30 Tage, Refresh-Token 180 Tage, jede Erneuerung liefert
 *   einen neuen Refresh-Token.** Darum wie bei Garmin unter Sperre und mit
 *   frisch gelesener Zeile.
 * - **Zustandslos.** Jeder `tools/call` geht ohne `initialize` und ohne
 *   `Mcp-Session-Id` durch.
 * - **Antworten sind Text fuer Sprachmodelle**, kein JSON und kein
 *   `structuredContent`. `callTool()` gibt den Text zurueck; wer daraus
 *   etwas liest, liest es aus Text.
 *
 * Kommt die Partner API, ersetzt sie diese Klasse hinter `CorosCalendar`;
 * die Verbindung und der Abgleich bleiben.
 */
class CorosClient
{
    public const SCOPES = 'openid offline_access mcp.tools';

    /** So lange vor Ablauf wird erneuert. Der Token haelt 30 Tage. */
    public const REFRESH_MARGIN_SECONDS = 2 * 86400;

    private const CLIENT_CACHE_KEY = 'coros:client-id:v1';

    public function enabled(): bool
    {
        return (bool) config('services.coros.enabled');
    }

    private function base(): string
    {
        return rtrim((string) config('services.coros.base_url'), '/');
    }

    private function redirect(): string
    {
        return (string) config('services.coros.redirect');
    }

    /**
     * Die Client-ID fuer neue Verbindungen. Einmal registriert und gemerkt;
     * je Rueckweg-URL, damit eine lokale Umgebung nicht die von zone3.run
     * benutzt.
     */
    public function clientId(): string
    {
        if ($id = config('services.coros.client_id')) {
            return (string) $id;
        }

        return Cache::rememberForever(self::CLIENT_CACHE_KEY . ':' . sha1($this->redirect()), function () {
            $response = Http::acceptJson()->timeout(20)->post($this->base() . '/connect/register', [
                'client_name'                => 'Zone3',
                'redirect_uris'              => [$this->redirect()],
                'grant_types'                => ['authorization_code', 'refresh_token'],
                'response_types'             => ['code'],
                'scope'                      => self::SCOPES,
                'token_endpoint_auth_method' => 'none',
            ]);

            $id = $response->json('client_id');
            if (! $response->successful() || ! is_string($id) || $id === '') {
                throw new CorosException('COROS hat die Registrierung abgelehnt: ' . Str::limit($response->body(), 200), $response->status());
            }

            Log::info('COROS-Client registriert', ['client_id' => $id, 'redirect' => $this->redirect()]);

            return $id;
        });
    }

    public function makeVerifier(): string
    {
        return Str::random(64);
    }

    public function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function authorizationUrl(string $clientId, string $state, string $verifier): string
    {
        return $this->base() . '/oauth2/authorize?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => $this->redirect(),
            'scope'                 => self::SCOPES,
            'state'                 => $state,
            'code_challenge'        => $this->challengeFor($verifier),
            'code_challenge_method' => 'S256',
            'resource'              => $this->base() . '/mcp',
        ]);
    }

    /** @return array{access_token:string, refresh_token:string, expires_in:int, id_token?:string} */
    public function exchangeCode(string $clientId, string $code, string $verifier): array
    {
        return $this->tokenRequest([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirect(),
            'client_id'     => $clientId,
            'code_verifier' => $verifier,
            'resource'      => $this->base() . '/mcp',
        ]);
    }

    /** Die Felder einer Token-Antwort, wie `coros_connections` sie speichert. */
    public function tokenFields(array $token): array
    {
        return [
            'access_token'            => $token['access_token'],
            'refresh_token'           => $token['refresh_token'],
            'access_token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 0)),
        ];
    }

    /**
     * `sub` aus dem id_token. Ohne Pruefung der Signatur: der Token kommt
     * direkt vom Token-Endpunkt ueber TLS, nicht aus dem Browser.
     */
    public function subject(array $token): ?string
    {
        $parts = explode('.', (string) ($token['id_token'] ?? ''));
        if (count($parts) < 2) {
            return null;
        }

        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($claims) && isset($claims['sub']) ? (string) $claims['sub'] : null;
    }

    public function accessToken(CorosConnection $connection): string
    {
        if ($this->expiresSoon($connection)) {
            $this->refresh($connection);
        }

        return $connection->access_token;
    }

    public function refresh(CorosConnection $connection, bool $force = false): void
    {
        Cache::lock('coros-refresh:' . $connection->user_id, 30)->block(20, function () use ($connection, $force) {
            $connection->refresh();

            if (! $force && ! $this->expiresSoon($connection)) {
                return;
            }

            try {
                $token = $this->tokenRequest([
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $connection->refresh_token,
                    'client_id'     => $connection->client_id,
                ]);
            } catch (CorosException $e) {
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

    /** Beim Trennen: den Refresh-Token bei COROS ungueltig machen. */
    public function revoke(CorosConnection $connection): void
    {
        Http::asForm()->timeout(20)->post($this->base() . '/oauth2/revoke', [
            'token'           => $connection->refresh_token,
            'token_type_hint' => 'refresh_token',
            'client_id'       => $connection->client_id,
        ])->throw();
    }

    /**
     * Ein MCP-Werkzeug aufrufen und den Text der Antwort zurueckgeben.
     *
     * Ein 401 wird einmal mit erneuertem Token wiederholt — COROS kann einen
     * Token vor seinem Ablaufdatum verwerfen. `isError` wird zur Ausnahme,
     * mit dem Text, den COROS dazu schreibt.
     */
    public function callTool(CorosConnection $connection, string $tool, array $arguments = []): string
    {
        $response = $this->post($this->accessToken($connection), $tool, $arguments);

        if ($response->status() === 401) {
            $this->refresh($connection, force: true);
            $response = $this->post($connection->access_token, $tool, $arguments);
        }

        if (! $response->successful()) {
            throw new CorosException("COROS {$tool}: HTTP {$response->status()} " . Str::limit($response->body(), 200), $response->status());
        }

        $message = $this->decode($response->body(), (string) $response->header('Content-Type'));

        if (isset($message['error'])) {
            throw new CorosException("COROS {$tool}: " . ($message['error']['message'] ?? 'Fehler'));
        }

        $result = $message['result'] ?? [];
        $text   = collect($result['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        // Manche Antworten sind als JSON-String verpackt („"Workout created…"“).
        $unquoted = json_decode($text, true);
        if (is_string($unquoted)) {
            $text = $unquoted;
        }

        if (! empty($result['isError'])) {
            throw new CorosException("COROS {$tool}: " . Str::limit($text, 300));
        }

        return $text;
    }

    private function post(string $accessToken, string $tool, array $arguments)
    {
        return Http::withToken($accessToken)
            ->withHeaders([
                'Accept'               => 'application/json, text/event-stream',
                'MCP-Protocol-Version' => '2025-06-18',
            ])
            ->timeout(60)
            ->post($this->base() . '/mcp', [
                'jsonrpc' => '2.0',
                'id'      => (int) (microtime(true) * 1000),
                'method'  => 'tools/call',
                'params'  => ['name' => $tool, 'arguments' => $arguments ?: new \stdClass()],
            ]);
    }

    /** JSON oder Server-Sent Events — MCP erlaubt beides. */
    private function decode(string $body, string $contentType): array
    {
        if (str_contains($contentType, 'text/event-stream')) {
            $data = collect(preg_split('/\r?\n/', $body))
                ->filter(fn ($line) => str_starts_with($line, 'data:'))
                ->map(fn ($line) => trim(substr($line, 5)))
                ->last();

            return json_decode((string) $data, true) ?: [];
        }

        return json_decode($body, true) ?: [];
    }

    private function expiresSoon(CorosConnection $connection): bool
    {
        return ! $connection->access_token_expires_at
            || $connection->access_token_expires_at->lte(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));
    }

    private function tokenRequest(array $fields): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post($this->base() . '/oauth2/token', $fields);
        } catch (Throwable $e) {
            throw new CorosException('COROS nicht erreichbar: ' . $e->getMessage());
        }

        $token = $response->json();
        if (! $response->successful() || empty($token['access_token']) || empty($token['refresh_token'])) {
            throw new CorosException('COROS hat den Token abgelehnt: ' . Str::limit($response->body(), 200), $response->status());
        }

        return $token;
    }
}
