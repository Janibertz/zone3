<?php

namespace App\Services;

use App\Models\GarminAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GarminService
{
    private string $baseUrl;

    public function __construct()
    {
        $port = env('GARMIN_SERVICE_PORT', 8001);
        $this->baseUrl = "http://127.0.0.1:{$port}";
    }

    // ── Auth helpers ──────────────────────────────────────────────────────────

    public function testConnection(GarminAccount $account): array
    {
        try {
            $response = Http::timeout(30)->post("{$this->baseUrl}/test", [
                'email'    => $account->email,
                'password' => $account->getDecryptedPassword(),
            ]);
            return $response->json();
        } catch (\Throwable $e) {
            $msg = str_contains($e->getMessage(), 'Connection refused') || str_contains($e->getMessage(), 'Failed to connect')
                ? 'Garmin-Service läuft noch nicht. Bitte warte kurz und versuche es erneut.'
                : $e->getMessage();
            return ['ok' => false, 'error' => $msg];
        }
    }

    public function ensureFreshSession(GarminAccount $account): void
    {
        // With the Python microservice, garth handles sessions internally.
        // We just verify the credentials still work when explicitly asked.
        $result = $this->testConnection($account);
        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException($result['error'] ?? 'Garmin-Verbindung fehlgeschlagen.');
        }
    }

    // ── Workout push ──────────────────────────────────────────────────────────

    public function pushSession(GarminAccount $account, array $session): bool
    {
        if (($session['type'] ?? '') === 'rest') return true;

        try {
            $response = Http::timeout(60)->post("{$this->baseUrl}/push-session", [
                'email'    => $account->email,
                'password' => $account->getDecryptedPassword(),
                'session'  => $session,
            ]);

            $data = $response->json();
            if (! ($data['ok'] ?? false)) {
                Log::warning('Garmin push-session failed', ['error' => $data['error'] ?? 'unknown', 'session' => $session['title'] ?? '']);
            }
            return (bool) ($data['ok'] ?? false);
        } catch (\Throwable $e) {
            Log::error('Garmin push-session exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function pushPlan(GarminAccount $account, array $sessions): array
    {
        try {
            $response = Http::timeout(120)->post("{$this->baseUrl}/push-plan", [
                'email'    => $account->email,
                'password' => $account->getDecryptedPassword(),
                'sessions' => $sessions,
            ]);
            return $response->json();
        } catch (\Throwable $e) {
            Log::error('Garmin push-plan exception', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
