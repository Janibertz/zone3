<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermsPageTest extends TestCase
{
    use RefreshDatabase;

    /** ROUVY und andere Partner prüfen die Seite ohne Konto. */
    public function test_terms_are_public(): void
    {
        $this->get('/nutzungsbedingungen')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Terms'));

        $this->get('/terms')->assertStatus(301)->assertRedirect('/nutzungsbedingungen');
    }

    /** Wer auf der Warteliste steht, muss lesen können, was er akzeptiert. */
    public function test_waitlisted_users_can_read_the_terms(): void
    {
        $user = User::factory()->create(['waitlisted_at' => now()]);

        $this->actingAs($user)->get('/nutzungsbedingungen')->assertOk();
    }
}
