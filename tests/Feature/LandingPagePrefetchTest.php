<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gemeldet: „Wenn ich zone3.run ohne Login aufrufe, braucht die Startseite
 * super lange."
 *
 * Im Browser gemessen: 63 Preload- und Prefetch-Verweise im HTML, 61 davon
 * `rel="prefetch"`. `Vite::prefetch()` stand global im AppServiceProvider
 * und hängte damit an jede Seite die komplette Anwendung — auch an die
 * Startseite. Wer zone3.run zum ersten Mal aufruft und noch kein Konto hat,
 * lud im Hintergrund Dashboard, Aktivitäten, Workouts und Kalender
 * herunter. Über Mobilfunk ist das die Wartezeit, die niemand versteht.
 */
class LandingPagePrefetchTest extends TestCase
{
    use RefreshDatabase;

    private function prefetchCount(string $html): int
    {
        return substr_count($html, 'rel="prefetch"');
    }

    public function test_a_guest_gets_no_prefetch_of_the_whole_app(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(0, $this->prefetchCount($html), 'Die Startseite laedt nicht die ganze Anwendung vor');
    }

    /** Auch die anderen oeffentlichen Seiten nicht. */
    public function test_the_support_page_is_light_too(): void
    {
        $html = $this->get('/support')->assertOk()->getContent();

        $this->assertSame(0, $this->prefetchCount($html));
    }

    /**
     * Wer angemeldet ist, profitiert davon — dort ist der naechste Klick
     * wirklich eine dieser Seiten.
     *
     * Geprueft wird die Strategie, nicht das HTML: die Testumgebung rendert
     * ueberhaupt keine Vite-Tags, dort steht auch fuer angemeldete Nutzer
     * nichts im Markup. Die Strategie ist der Schalter, an dem es haengt.
     */
    public function test_a_signed_in_athlete_still_gets_the_app_prefetched(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertSame('waterfall', $this->prefetchStrategy());
    }

    /** Und fuer den Gast bleibt der Schalter aus. */
    public function test_the_strategy_stays_off_for_a_guest(): void
    {
        $this->get('/')->assertOk();

        $this->assertNull($this->prefetchStrategy());
    }

    /** Der interne Schalter von Illuminate\Foundation\Vite. */
    private function prefetchStrategy(): ?string
    {
        $property = new \ReflectionProperty(\Illuminate\Foundation\Vite::class, 'prefetchStrategy');

        return $property->getValue(app(\Illuminate\Foundation\Vite::class));
    }
}
