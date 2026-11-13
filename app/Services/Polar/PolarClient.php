<?php

namespace App\Services\Polar;

use App\Models\PolarConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Transport zu Polar AccessLink v3 (Doku in `polar-suunto/` der Projektdateien).
 *
 * - **OAuth2 ohne PKCE und ohne Refresh-Token.** Der Code wird mit Basic-Auth
 *   (Client-ID:Secret) getauscht; der Access-Token gilt, bis Polar oder der
 *   Athlet ihn widerruft. Die Antwort trägt `x_user_id`.
 * - **Nach dem Token muss der Athlet registriert werden** (`POST /v3/users`),
 *   erst dann gibt Polar Daten heraus. 409 = schon registriert, das ist gut.
 * - `/v3/users`, `/v3/exercises` mit dem Token des Athleten, `/v3/webhooks`
 *   mit Basic-Auth des Clients.
 * - **Trainings nur der letzten 30 Tage und erst ab Registrierung** — es gibt
 *   keinen Rückblick.
 */
class PolarClient
{
    public const SCOPE = 'accesslink.read_all';

    public function configured(): bool
    {
        return filled(config('services.polar.client_id')) && filled(config('services.polar.client_secret'));
    }

    private function api(): string
    {
        return rtrim((string) config('services.polar.api_url'), '/');
    }

    public function authorizationUrl(string $state): string
    {
        return config('services.polar.auth_url') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => config('services.polar.client_id'),
            'redirect_uri'  => config('services.polar.redirect'),
            'scope'         => self::SCOPE,
            'state'         => $state,
        ]);
    }

    /** @return array{access_token:string, x_user_id:int} */
    public function exchangeCode(string $code): array
    {
        try {
            $response = $this->asClient()->asForm()->post((string) config('services.polar.token_url'), [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => config('services.polar.redirect'),
            ]);
        } catch (Throwable $e) {
            throw new PolarException('Polar nicht erreichbar: ' . $e->getMessage());
        }

        $token = $response->json();
        if (! $response->successful() || empty($token['access_token']) || empty($token['x_user_id'])) {
            throw new PolarException('Polar hat den Code abgelehnt: ' . Str::limit($response->body(), 200), $response->status());
        }

        return $token;
    }

    /** Den Athleten bei Zone3s Client anmelden. 409 = war es schon. */
    public function register(string $accessToken, string $memberId): void
    {
        $response = Http::withToken($accessToken)->acceptJson()->timeout(20)
            ->post($this->api() . '/v3/users', ['member-id' => $memberId]);

        if ($response->successful() || $response->status() === 409) {
            return;
        }

        throw new PolarException(
            $response->status() === 403
                ? 'Polar: der Athlet hat nicht alle Pflicht-Einwilligungen in Polar Flow bestätigt.'
                : 'Polar-Registrierung: HTTP ' . $response->status() . ' ' . Str::limit($response->body(), 200),
            $response->status(),
        );
    }

    /** Beim Trennen: abmelden, das widerruft den Token bei Polar. */
    public function deregister(PolarConnection $connection): void
    {
        $response = $this->asUser($connection)->delete($this->api() . '/v3/users/' . $connection->polar_user_id);

        if (! $response->successful() && ! in_array($response->status(), [401, 403, 404], true)) {
            throw new PolarException('Polar-Abmeldung: HTTP ' . $response->status(), $response->status());
        }
    }

    /**
     * Die Trainings der letzten 30 Tage (nur Zusammenfassungen).
     *
     * @return list<array>
     */
    public function exercises(PolarConnection $connection): array
    {
        $data = $this->userGet($connection, '/v3/exercises')->json();

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /** Ein Datenendpunkt des Athleten als JSON (Schlaf, Nightly Recharge, Physical info). */
    public function userData(PolarConnection $connection, string $path): array
    {
        $response = $this->asUser($connection)->get($this->api() . $path);
        // 204 = keine Daten.
        if ($response->status() === 204) {
            return [];
        }
        $this->guard($response, $path);

        return (array) $response->json();
    }

    public function exercise(PolarConnection $connection, string $id): array
    {
        return (array) $this->userGet($connection, '/v3/exercises/' . rawurlencode($id))->json();
    }

    /** Die FIT-Datei eines Trainings; null, wenn Polar keine hat. */
    public function fit(PolarConnection $connection, string $id): ?string
    {
        $response = $this->asUser($connection)->accept('*/*')->timeout(60)
            ->get($this->api() . '/v3/exercises/' . rawurlencode($id) . '/fit');

        if ($response->status() === 404) {
            return null;
        }
        $this->guard($response, 'FIT');

        return $response->body();
    }

    // ── Webhook (einer pro Client, Basic-Auth) ──────────────────────────────

    /** @return array|null der eingerichtete Webhook */
    public function webhook(): ?array
    {
        $response = $this->asClient()->get($this->api() . '/v3/webhooks');
        $this->guard($response, 'Webhook lesen');

        $data = $response->json('data');
        if (is_array($data) && array_is_list($data)) {
            $data = $data[0] ?? null;
        }

        return is_array($data) && $data !== [] ? $data : null;
    }

    /** @return array{id:string, signature_secret_key:string} */
    public function createWebhook(string $url, array $events): array
    {
        $response = $this->asClient()->post($this->api() . '/v3/webhooks', ['events' => $events, 'url' => $url]);
        $this->guard($response, 'Webhook anlegen');

        $data = (array) $response->json('data');
        if (empty($data['signature_secret_key'])) {
            throw new PolarException('Polar hat keinen Webhook-Schlüssel geliefert.');
        }

        return $data;
    }

    public function deleteWebhook(string $id): void
    {
        $response = $this->asClient()->delete($this->api() . '/v3/webhooks/' . rawurlencode($id));
        if ($response->status() !== 404) {
            $this->guard($response, 'Webhook löschen');
        }
    }

    public function activateWebhook(): void
    {
        $this->guard($this->asClient()->post($this->api() . '/v3/webhooks/activate'), 'Webhook aktivieren');
    }

    // ────────────────────────────────────────────────────────────────────────

    private function userGet(PolarConnection $connection, string $path): Response
    {
        $response = $this->asUser($connection)->get($this->api() . $path);
        $this->guard($response, $path);

        return $response;
    }

    private function asUser(PolarConnection $connection): PendingRequest
    {
        return Http::withToken($connection->access_token)->acceptJson()->timeout(30);
    }

    private function asClient(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.polar.client_id'), (string) config('services.polar.client_secret'))
            ->acceptJson()->timeout(30);
    }

    private function guard(Response $response, string $what): void
    {
        if (! $response->successful()) {
            throw new PolarException("Polar {$what}: HTTP {$response->status()} " . Str::limit($response->body(), 200), $response->status());
        }
    }
}
