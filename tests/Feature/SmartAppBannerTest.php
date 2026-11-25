<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Apples Smart App Banner: Startseite, Blog, Ratgeber und FAQ, sonst nirgends. */
class SmartAppBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_carries_the_banner(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<meta name="apple-itunes-app" content="app-id=6832175374">', false);
    }

    public function test_blog_guide_and_faq_carry_the_banner(): void
    {
        foreach (['/blog', '/ratgeber', '/faq'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('<meta name="apple-itunes-app" content="app-id=6832175374">', false);
        }
    }

    public function test_other_pages_do_not(): void
    {
        $this->get('/privacy')->assertOk()->assertDontSee('apple-itunes-app', false);
    }
}
