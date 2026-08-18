<?php

namespace Tests\Feature;

use App\Services\AI\OpenAIClient;
use App\Services\FitClient;
use Tests\TestCase;

/**
 * Die Dienste muessen sich auch ohne konfigurierte Zugangsdaten erzeugen
 * lassen.
 *
 * OpenAIClient deklariert $apiKey als string und las config(), das ohne
 * Umgebungsvariable null liefert — der Container warf einen TypeError schon
 * beim Aufloesen der Klasse, lange bevor jemand einen Aufruf machen wollte.
 * Auf einem Rechner mit gepflegter .env faellt das nie auf; in der CI
 * scheiterten daran 24 Tests, die mit OpenAI gar nichts zu tun hatten.
 *
 * Ein fehlender Schluessel soll beim Aufruf scheitern, nicht beim Hochfahren.
 */
class ServiceBootTest extends TestCase
{
    public function test_the_openai_client_boots_without_a_key(): void
    {
        config([
            'services.openai.api_key'    => null,
            'services.openai.model'      => null,
            'services.openai.model_mini' => null,
        ]);

        $client = app(OpenAIClient::class);

        $this->assertNotEmpty($client->main(), 'Ohne Angabe muss ein Standardmodell greifen');
        $this->assertNotEmpty($client->mini());
    }

    /** Dasselbe fuer die abhaengigen Fachdienste. */
    public function test_the_ai_services_boot_without_a_key(): void
    {
        config(['services.openai.api_key' => null]);

        foreach ([
            \App\Services\AI\CoachChatService::class,
            \App\Services\AI\CoachingTextService::class,
            \App\Services\AI\SessionContentService::class,
            \App\Services\AI\TrainingPlanGenerator::class,
            \App\Services\AI\AthleteProfileService::class,
        ] as $service) {
            $this->assertNotNull(app($service), $service . ' liess sich nicht erzeugen');
        }
    }

    public function test_the_fit_client_boots_without_configuration(): void
    {
        config(['services.fit.service_url' => null, 'services.fit.token' => null]);

        $this->assertFalse(app(FitClient::class)->isConfigured());
    }
}
