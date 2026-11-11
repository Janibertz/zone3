<?php

namespace Tests\Feature;

use App\Services\Content\Articles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ratgeber und Blog: öffentliche Blade-Seiten aus Markdown-Dateien.
 */
class ArticlePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_lists_and_the_first_articles_are_public(): void
    {
        $this->get('/ratgeber')->assertOk()->assertSee('Laufen im Winter');
        $this->get('/blog')->assertOk()->assertSee('Neu in Zone3');
        $this->get('/ratgeber/laufen-im-winter')->assertOk()
            ->assertSee('Zwiebelprinzip')
            ->assertSee('application/ld+json', false)
            ->assertSee('ref=ratgeber', false);
        $this->get('/ratgeber/gibt-es-nicht')->assertNotFound();
    }

    public function test_the_sitemap_lists_every_article(): void
    {
        $this->get('/sitemap.xml')->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee(route('articles.show', ['kind' => 'ratgeber', 'slug' => 'laufen-im-winter']), false);
    }

    public function test_front_matter_is_read_and_html_is_stripped(): void
    {
        $a = Articles::parse('ratgeber', 'probe', "---\ntitle: Titel: mit Doppelpunkt\ndate: 2026-11-01\n---\n## Kopf\n\n<script>x</script>Text");

        $this->assertSame('Titel: mit Doppelpunkt', $a['title']);
        $this->assertStringContainsString('<h2>Kopf</h2>', $a['html']);
        $this->assertStringNotContainsString('<script>', $a['html']);
    }
}
