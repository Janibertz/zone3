<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Apples Smart App Banner steht nur auf der Startseite. */
class SmartAppBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_carries_the_banner(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<meta name="apple-itunes-app" content="app-id=6832175374">', false);
    }

    public function test_other_pages_do_not(): void
    {
        $this->get('/privacy')->assertOk()->assertDontSee('apple-itunes-app', false);
    }
}
