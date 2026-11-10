<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Services\Auth\SignupSource;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

/**
 * Woher eine Anmeldung kommt (10.11.2026): `?ref=` bzw. `utm_source`
 * landet in `users.signup_source`.
 */
class SignupSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser');
        $this->app->instance(WebPushService::class, $push);
    }

    private function signInByLink(string $email): void
    {
        $this->post(route('login.link'), ['email' => $email]);
        $url = null;
        Notification::assertSentOnDemand(MagicLinkNotification::class, function ($n, $c, AnonymousNotifiable $to) use (&$url) {
            $url = $n->toMail($to)->actionUrl;

            return true;
        });
        $this->post(route('login.link.consume', basename(parse_url($url, PHP_URL_PATH))));
    }

    public function test_the_ref_of_the_first_visit_reaches_the_new_account(): void
    {
        $this->get('/login?ref=Instagram');
        $this->get('/login?ref=reddit'); // die erste Quelle gewinnt

        $this->signInByLink('neu@example.com');

        $this->assertSame('instagram', User::where('email', 'neu@example.com')->value('signup_source'));
    }

    public function test_the_source_survives_a_link_opened_in_another_browser(): void
    {
        $this->get('/login?utm_source=story');
        $this->post(route('login.link'), ['email' => 'safari@example.com']);
        $url = null;
        Notification::assertSentOnDemand(MagicLinkNotification::class, function ($n, $c, AnonymousNotifiable $to) use (&$url) {
            $url = $n->toMail($to)->actionUrl;

            return true;
        });

        $this->flushSession(); // Mail-App → Safari: keine Session
        $this->post(route('login.link.consume', basename(parse_url($url, PHP_URL_PATH))));

        $this->assertSame('story', User::where('email', 'safari@example.com')->value('signup_source'));
    }

    public function test_without_ref_the_source_stays_empty(): void
    {
        $this->signInByLink('ohne@example.com');

        $this->assertNull(User::where('email', 'ohne@example.com')->value('signup_source'));
    }

    public function test_an_invitation_counts_as_its_own_source(): void
    {
        $invite = Invitation::create(['token' => 'abc123', 'expires_at' => now()->addDays(3)]);

        $this->post(route('register'), [
            'invite' => $invite->token, 'name' => 'Gast', 'email' => 'gast@example.com',
            'password' => 'Geheim-12345', 'password_confirmation' => 'Geheim-12345',
        ]);

        $this->assertSame(SignupSource::INVITATION, User::where('email', 'gast@example.com')->value('signup_source'));
    }

    public function test_odd_values_are_cleaned(): void
    {
        $this->assertSame('insta-reel_2', SignupSource::clean(' Insta-Reel_2!? '));
        $this->assertNull(SignupSource::clean('<>'));
        $this->assertSame(40, strlen(SignupSource::clean(str_repeat('a', 80))));
    }
}
