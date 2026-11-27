<?php

namespace App\Services\Instagram;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Transport zur Instagram API mit Instagram-Login (graph.instagram.com).
 *
 * Der Token kommt zuerst aus app_settings (dort legt ihn die Verlängerung ab,
 * verschlüsselt), sonst aus INSTAGRAM_ACCESS_TOKEN. Ein langlebiger Token gilt
 * 60 Tage und lässt sich verlängern, solange er gilt und mindestens 24 h alt ist.
 */
class InstagramClient
{
    public const TOKEN_KEY = 'instagram_access_token';

    public function configured(): bool
    {
        return filled($this->accountId()) && filled($this->token());
    }

    public function accountId(): ?string
    {
        return config('services.instagram.account_id');
    }

    public function token(): ?string
    {
        $stored = AppSetting::get(self::TOKEN_KEY);
        if ($stored) {
            try {
                return self::clean(Crypt::decryptString($stored));
            } catch (Throwable) {
                // kaputter Eintrag: auf die Umgebung zurückfallen
            }
        }

        return self::clean(config('services.instagram.access_token'));
    }

    /** Leerzeichen, Anführungszeichen oder ein „Bearer“ aus dem Kopieren weg. */
    public static function clean(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }
        $token = trim(preg_replace('/^\s*Bearer\s+/i', '', $token));

        return trim($token, " \t\n\r\0\x0B\"'") ?: null;
    }

    /** Legt einen Container an und gibt seine ID zurück. */
    public function createContainer(array $params): string
    {
        $json = $this->post($this->accountId() . '/media', $params);

        return (string) ($json['id'] ?? throw new RuntimeException('Instagram: Container ohne ID'));
    }

    /** IN_PROGRESS, FINISHED, ERROR, EXPIRED oder PUBLISHED. */
    public function containerStatus(string $containerId): array
    {
        return $this->get($containerId, ['fields' => 'status_code,status']);
    }

    public function publish(string $containerId): string
    {
        $json = $this->post($this->accountId() . '/media_publish', ['creation_id' => $containerId]);

        return (string) ($json['id'] ?? throw new RuntimeException('Instagram: Veröffentlichung ohne ID'));
    }

    public function permalink(string $mediaId): ?string
    {
        try {
            return $this->get($mediaId, ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Base URL for a token from Facebook login (EAA…). */
    public const FACEBOOK_API_URL = 'https://graph.facebook.com/v21.0';

    /**
     * Ein Token aus dem Facebook-Login (EAA…) statt aus dem Instagram-Login
     * (IGAA…). Er geht nur über graph.facebook.com, mit derselben Konto-ID.
     */
    public function usesFacebookToken(): bool
    {
        return str_starts_with((string) $this->token(), 'EAA');
    }

    /** Verlängert den Token um 60 Tage und legt ihn ab. 0 = nicht verlängerbar (Facebook-Token). */
    public function refreshToken(): int
    {
        if ($this->usesFacebookToken()) {
            return 0;
        }

        $response = Http::timeout(30)->get(rtrim(config('services.instagram.api_url'), '/') . '/refresh_access_token', [
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $this->token(),
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('Instagram: Token-Verlängerung abgelehnt: ' . $this->errorText($response->json(), $response->status()));
        }

        AppSetting::set(self::TOKEN_KEY, Crypt::encryptString($response->json('access_token')));

        return (int) $response->json('expires_in', 0);
    }

    private function get(string $path, array $query): array
    {
        $response = Http::timeout(30)->get($this->url($path), $query + ['access_token' => $this->token()]);

        return $this->handle($response);
    }

    private function post(string $path, array $params): array
    {
        $response = Http::timeout(60)->asForm()->post($this->url($path), $params + ['access_token' => $this->token()]);

        return $this->handle($response);
    }

    private function handle($response): array
    {
        $json = $response->json() ?? [];
        if (! $response->successful() || isset($json['error'])) {
            throw new RuntimeException('Instagram: ' . $this->errorText($json, $response->status()));
        }

        return $json;
    }

    private function errorText(?array $json, int $status): string
    {
        $error = $json['error'] ?? [];
        $text  = $error['error_user_msg'] ?? $error['message'] ?? 'unbekannter Fehler';

        return "HTTP {$status}: {$text}";
    }

    private function url(string $path): string
    {
        $base = $this->usesFacebookToken() ? self::FACEBOOK_API_URL : config('services.instagram.api_url');

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
