<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Der Zugang zum fit-service (Garmin-Anbindung und FIT-Erzeugung).
 *
 * Bisher baute jede der fünf Aufrufstellen ihren HTTP-Aufruf selbst
 * zusammen — Adresse, Zeitlimit, Fehlerbehandlung jeweils eigen. Das war
 * schon vorher unschön; mit einem gemeinsamen Geheimnis wäre es gefährlich
 * geworden: es hätte gereicht, es an einer Stelle zu vergessen.
 *
 * Der Dienst nimmt Garmin-Zugangsdaten entgegen und liefert
 * Gesundheitsdaten zurück. Ohne Token darf ihn niemand erreichen.
 */
class FitClient
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $token   = null,
    ) {}

    private function url(): ?string
    {
        return $this->baseUrl ?? config('services.fit.service_url');
    }

    private function secret(): ?string
    {
        return $this->token ?? config('services.fit.token');
    }

    /** Ist der Dienst überhaupt eingerichtet? */
    public function isConfigured(): bool
    {
        return ! empty($this->url());
    }

    /**
     * Aufruf an den Dienst. Der Token geht als Kopfzeile mit; fehlt er in
     * der Konfiguration, geht der Aufruf trotzdem raus — die Ablehnung
     * kommt dann vom Dienst und steht als 401 im Log, statt hier still zu
     * verschwinden.
     */
    public function post(string $path, array $payload, int $timeout = 30): Response
    {
        $request = Http::timeout($timeout);

        if ($secret = $this->secret()) {
            $request = $request->withHeaders(['X-Fit-Token' => $secret]);
        }

        return $request->post(rtrim($this->url(), '/') . '/' . ltrim($path, '/'), $payload);
    }
}
