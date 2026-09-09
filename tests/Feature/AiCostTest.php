<?php

namespace Tests\Feature;

use App\Models\AiLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Was ein Modellaufruf kostet.
 *
 * Die Preistabelle kannte monatelang nur `gpt-4o` und `gpt-4o-mini` und fiel
 * fuer alles andere still auf `gpt-4o` zurueck. Gelaufen ist in der Zeit
 * `gpt-5.5-2026-04-23` — also war JEDE Euro-Zahl im AI-Log mit den Preisen
 * eines Modells gerechnet, das gar nicht im Einsatz war.
 *
 * Auffallen konnte das nicht: eine falsche Zahl sieht aus wie eine richtige.
 * Genau deshalb steht hier ein Test — und deshalb ist der Fall „Modell
 * unbekannt" ein sichtbarer und kein stiller.
 */
class AiCostTest extends TestCase
{
    use RefreshDatabase;

    /** 1M Eingabe + 1M Ausgabe macht die Listenpreise direkt ablesbar. */
    private function costFor(string $model): float
    {
        return AiLog::calculateCost($model, 1_000_000, 1_000_000);
    }

    public function test_each_model_is_billed_with_its_own_price(): void
    {
        // Listenpreise in USD, hier mit dem Kurs 0.92 in Euro.
        $this->assertEqualsWithDelta((10.00 + 50.00) * 0.92, $this->costFor('gpt-6-astra'), 0.01);
        $this->assertEqualsWithDelta((4.00 + 20.00) * 0.92, $this->costFor('gpt-5.6-sol'), 0.01);
        $this->assertEqualsWithDelta((2.00 + 12.00) * 0.92, $this->costFor('gpt-5.6-terra'), 0.01);
        $this->assertEqualsWithDelta((0.20 + 1.20) * 0.92, $this->costFor('gpt-5.6-luna'), 0.01);
    }

    public function test_the_two_models_in_use_are_priced_apart(): void
    {
        $main = $this->costFor(config('services.openai.model'));
        $mini = $this->costFor(config('services.openai.model_mini'));

        $this->assertGreaterThan(0, $main, 'Das Hauptmodell hat einen Preis');
        $this->assertGreaterThan(0, $mini, 'Das kleine Modell hat einen Preis');
        $this->assertGreaterThan($mini, $main, 'Das kleine Modell ist billiger — sonst hat es keinen Zweck');
    }

    /**
     * Der eigentliche Ausloeser: konfiguriert war `gpt-5.5-2026-04-23`, in
     * der Tabelle stand `gpt-5.5` — der exakte Schluesselvergleich fand
     * nichts und fiel auf GPT-4o zurueck.
     */
    public function test_a_dated_model_id_finds_its_price(): void
    {
        $this->assertSame(
            $this->costFor('gpt-5.6-sol'),
            $this->costFor('gpt-5.6-sol-2026-08-01'),
            'Ein Datumsanhang darf den Preis nicht verlieren',
        );
    }

    /**
     * Ein unbekanntes Modell kostet nichts UND meldet sich. Frueher bekam es
     * stillschweigend GPT-4o-Preise, und niemand konnte den Unterschied
     * sehen.
     */
    public function test_an_unknown_model_is_loud_instead_of_wrong(): void
    {
        Log::spy();

        $cost = $this->costFor('gpt-7-irgendwas');

        $this->assertSame(0.0, $cost);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'Kein Preis')
                && ($context['model'] ?? null) === 'gpt-7-irgendwas');
    }

    public function test_the_cost_scales_with_the_tokens(): void
    {
        $small = AiLog::calculateCost('gpt-5.6-sol', 1_000, 1_000);
        $big   = AiLog::calculateCost('gpt-5.6-sol', 10_000, 10_000);

        $this->assertEqualsWithDelta($small * 10, $big, 0.000001);
    }

    /** Ausgabe ist teurer als Eingabe — bei jedem dieser Modelle. */
    public function test_output_costs_more_than_input(): void
    {
        foreach (['gpt-6-astra', 'gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna'] as $model) {
            $in  = AiLog::calculateCost($model, 1_000_000, 0);
            $out = AiLog::calculateCost($model, 0, 1_000_000);

            $this->assertGreaterThan($in, $out, "{$model}: Ausgabe muss teurer sein");
        }
    }
}
