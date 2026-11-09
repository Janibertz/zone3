<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Worin Zone3 benutzt wird: App, Homescreen, Browser (09.11.2026). */
class UsageSeenTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_each_way_keeps_its_last_day(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson('/usage/seen', ['via' => 'app'])->assertNoContent();
        Carbon::setTestNow('2026-11-10 08:00:00');
        $this->actingAs($user)->postJson('/usage/seen', ['via' => 'browser'])->assertNoContent();

        $user->refresh();
        $this->assertSame('2026-11-09', $user->seen_app_on->toDateString());
        $this->assertSame('2026-11-10', $user->seen_browser_on->toDateString());
        $this->assertNull($user->seen_homescreen_on);
    }

    public function test_only_known_ways_and_signed_in(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson('/usage/seen', ['via' => 'android'])->assertUnprocessable();
        auth()->logout();
        $this->postJson('/usage/seen', ['via' => 'app'])->assertUnauthorized();
    }

    public function test_it_works_during_onboarding(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);

        $this->actingAs($user)->postJson('/usage/seen', ['via' => 'homescreen'])->assertNoContent();
        $this->assertNotNull($user->fresh()->seen_homescreen_on);
    }
}
