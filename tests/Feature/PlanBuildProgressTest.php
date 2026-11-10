<?php

namespace Tests\Feature;

use App\Jobs\GenerateEventTrainingPlanJob;
use App\Models\Event;
use App\Models\User;
use App\Services\PlanBuildProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Der Planbau Schritt für Schritt (Idee E, 10.11.2026): der Job meldet
 * echte Stationen, der Status-Endpunkt reicht sie an die Planseite weiter.
 */
class PlanBuildProgressTest extends TestCase
{
    use RefreshDatabase;

    private function event(User $user, array $attrs = []): Event
    {
        return Event::create(array_merge([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(60),
            'race_distance'       => '10km',
            'priority'            => 'A',
            'target_time_hours'   => 0,
            'target_time_minutes' => 45,
        ], $attrs));
    }

    public function test_later_stages_keep_earlier_facts(): void
    {
        PlanBuildProgress::mark(7, 'form', ['vdot' => 50.0, 'predicted' => null]);
        PlanBuildProgress::mark(7, 'weeks', ['days' => 14, 'runs' => 9]);

        $p = PlanBuildProgress::get(7);
        $this->assertSame('weeks', $p['stage']);
        $this->assertSame(50.0, $p['facts']['vdot']);
        $this->assertSame(14, $p['facts']['days']);
        $this->assertArrayNotHasKey('predicted', $p['facts'], 'Leere Werte stehen nicht als null in der Anzeige.');
    }

    public function test_starting_a_build_resets_the_steps(): void
    {
        Queue::fake();
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        PlanBuildProgress::mark($event->id, 'save', ['sessions' => 12]);

        $this->actingAs($user)->postJson(route('events.plan.generate', $event))->assertOk();

        Queue::assertPushed(GenerateEventTrainingPlanJob::class);
        $this->assertSame(['stage' => 'start', 'facts' => []], PlanBuildProgress::get($event->id));
    }

    public function test_status_carries_the_progress_while_generating(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user, ['plan_generating' => true, 'plan_generating_at' => now()]);
        PlanBuildProgress::mark($event->id, 'model', ['vdot' => 48.2]);

        $this->actingAs($user)->getJson(route('events.plan.generate-status', $event))
            ->assertOk()
            ->assertJsonPath('status', 'generating')
            ->assertJsonPath('progress.stage', 'model')
            ->assertJsonPath('progress.facts.vdot', 48.2);
    }
}
