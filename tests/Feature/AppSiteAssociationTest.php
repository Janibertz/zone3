<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppSiteAssociationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_callbacks_and_the_mail_link_open_the_app(): void
    {
        config(['services.apns.team_id' => 'Y7NR92XBVK', 'services.apns.bundle_id' => 'run.zone3.app']);

        $response = $this->get('/.well-known/apple-app-site-association');

        $response->assertOk();
        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));

        $details = $response->json('applinks.details.0');
        $this->assertSame(['Y7NR92XBVK.run.zone3.app'], $details['appIDs']);
        $this->assertSame(
            ['/garmin/callback*', '/coros/callback*', '/polar/callback*', '/whoop/callback*', '/strava/callback*', '/login/link/*'],
            array_column($details['components'], '/'),
        );
    }

    public function test_it_works_without_apns_settings(): void
    {
        config(['services.apns.team_id' => null, 'services.apns.bundle_id' => null]);

        $this->get('/.well-known/apple-app-site-association')
            ->assertOk()
            ->assertJsonPath('applinks.details.0.appIDs.0', 'Y7NR92XBVK.run.zone3.app');
    }
}
