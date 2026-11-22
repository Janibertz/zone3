<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\AI\OpenAIClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Jan, 22.11.2026: die Kostenbremse im Admin selbst ändern. */
class AdminAiBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_sets_five_euros_a_day(): void
    {
        $this->assertSame(5.0, OpenAIClient::dailyBudgetEur());
    }

    public function test_an_admin_changes_both_budgets(): void
    {
        $admin = User::factory()->onboarded()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.settings.budget'), ['daily_eur' => 7.5, 'user_eur' => 0.5])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(7.5, OpenAIClient::dailyBudgetEur());
        $this->assertSame(0.5, OpenAIClient::userDefaultBudgetEur());
    }

    public function test_an_athlete_cannot_change_it(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->patch(route('admin.settings.budget'), ['daily_eur' => 50, 'user_eur' => 5])
            ->assertForbidden();
        $this->assertSame(5.0, OpenAIClient::dailyBudgetEur());
    }

    public function test_the_setting_brakes_the_calls(): void
    {
        AppSetting::set(OpenAIClient::SETTING_DAILY_BUDGET, '2');
        AiLog::create(['call_type' => 'event_plan', 'model' => 'gpt-5.6-sol', 'status' => 'success', 'cost_eur' => 2.1]);

        $this->assertStringContainsString('Tagesbudget fuer alle', app(OpenAIClient::class)->blockReason('coach_chat'));
    }
}
