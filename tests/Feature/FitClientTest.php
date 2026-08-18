<?php

namespace Tests\Feature;

use App\Services\FitClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Der Zugang zum fit-service.
 *
 * Der Dienst nimmt Garmin-Zugangsdaten entgegen und gibt Gesundheitsdaten
 * heraus. Er verlangt jetzt ein gemeinsames Geheimnis — diese Tests halten
 * fest, dass es auch wirklich mitgeschickt wird. Vorher baute jede der fünf
 * Aufrufstellen ihren HTTP-Aufruf selbst; es hätte gereicht, den Header an
 * einer davon zu vergessen.
 */
class FitClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.fit.service_url' => 'https://fit.example.test',
            'services.fit.token'       => 'geheim-123',
        ]);
    }

    public function test_the_token_travels_with_every_call(): void
    {
        Http::fake(['fit.example.test/*' => Http::response(['ok' => true])]);

        app(FitClient::class)->post('/garmin-health', ['days' => 7]);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Fit-Token', 'geheim-123'));
    }

    public function test_the_path_is_joined_without_double_slashes(): void
    {
        config(['services.fit.service_url' => 'https://fit.example.test/']);
        Http::fake(['fit.example.test/*' => Http::response(['ok' => true])]);

        app(FitClient::class)->post('/garmin-login', []);

        Http::assertSent(fn ($request) => $request->url() === 'https://fit.example.test/garmin-login');
    }

    /**
     * Ohne Token geht der Aufruf trotzdem raus — die Ablehnung kommt dann als
     * 401 vom Dienst und steht im Log, statt hier still zu verschwinden.
     */
    public function test_without_a_token_the_header_is_omitted(): void
    {
        config(['services.fit.token' => null]);
        Http::fake(['fit.example.test/*' => Http::response(['ok' => true])]);

        app(FitClient::class)->post('/garmin-health', []);

        Http::assertSent(fn ($request) => ! $request->hasHeader('X-Fit-Token'));
    }

    public function test_it_knows_when_the_service_is_not_configured(): void
    {
        config(['services.fit.service_url' => null]);

        $this->assertFalse(app(FitClient::class)->isConfigured());
    }
}
