<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Übersicht ordnet jeder Athlet selbst. Gespeichert am Konto, damit
 * iPhone und Desktop dasselbe zeigen.
 */
class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function runner(array $attributes = []): User
    {
        return User::factory()->create(['onboarding_completed_at' => now()] + $attributes);
    }

    public function test_without_a_choice_the_default_order_applies(): void
    {
        $layout = DashboardLayout::resolve(null);

        $this->assertSame(DashboardLayout::keys(), array_column($layout, 'key'));
        $this->assertNotContains(true, array_column($layout, 'hidden'));
    }

    public function test_a_module_added_later_appears_behind_its_default_predecessor(): void
    {
        // Gespeichert, bevor es „intensitaet" gab: es gehört hinter „form",
        // nicht ans Ende, wo es niemand findet.
        $stored = array_values(array_diff(array_reverse(DashboardLayout::keys()), ['intensitaet']));

        $order = DashboardLayout::order($stored);

        $this->assertCount(count(DashboardLayout::keys()), $order);
        $this->assertSame(array_search('form', $order) + 1, array_search('intensitaet', $order));
    }

    public function test_unknown_and_duplicate_keys_are_dropped(): void
    {
        $order = DashboardLayout::order(['tempo', 'gibtsnicht', 'tempo', 42]);

        $this->assertSame(1, count(array_keys($order, 'tempo', true)));
        $this->assertEqualsCanonicalizing(DashboardLayout::keys(), $order);
    }

    public function test_the_athlete_saves_order_and_hidden_modules(): void
    {
        $user  = $this->runner();
        $order = array_reverse(DashboardLayout::keys());

        $this->actingAs($user)
            ->putJson(route('dashboard.layout'), ['order' => $order, 'hidden' => ['tempo']])
            ->assertOk()
            ->assertJsonPath('layout.0.key', $order[0]);

        $user->refresh();
        $this->assertSame($order, $user->dashboard_layout['order']);
        $this->assertSame(['tempo'], $user->dashboard_layout['hidden']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dashboardLayout.0.key', $order[0])
                ->where('dashboardLayout', fn ($layout) => collect($layout)->firstWhere('key', 'tempo')['hidden'] === true));
    }

    public function test_an_unknown_module_is_rejected(): void
    {
        $this->actingAs($this->runner())
            ->putJson(route('dashboard.layout'), ['order' => ['heute'], 'hidden' => []])
            ->assertUnprocessable();
    }

    public function test_reset_returns_to_the_default(): void
    {
        $user = $this->runner(['dashboard_layout' => ['order' => ['tempo'], 'hidden' => ['woche']]]);

        $this->actingAs($user)
            ->deleteJson(route('dashboard.layout.reset'))
            ->assertOk()
            ->assertJsonPath('layout.0.key', DashboardLayout::keys()[0]);

        $this->assertNull($user->fresh()->dashboard_layout);
    }
}
