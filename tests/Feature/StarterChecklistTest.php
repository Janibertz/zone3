<?php

namespace Tests\Feature;

use App\Models\CoachMessage;
use App\Models\Event;
use App\Models\PushSubscription;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\StarterChecklist;
use App\Services\WebPushService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Einsteiger-Checkliste auf „Heute“ (Jan, 07.11.2026): fest oben, Haken aus
 * den Daten, am Ende das Beta-Abzeichen, schliessen erst danach.
 */
class StarterChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Feste Gegenwart: „erste Woche geschafft“ hängt am Datum.
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->mock(WebPushService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturnNull());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runner(): User
    {
        return User::factory()->create(['onboarding_completed_at' => now()]);
    }

    private function done(User $user): array
    {
        return app(StarterChecklist::class)->done($user->fresh());
    }

    /** Alles, was die Liste verlangt — die Uhr bleibt optional weg. */
    private function finish(User $user): void
    {
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => '2026-11-03', 'type' => 'easy_run', 'title' => 'Locker',
            'distance_km' => 8, 'intensity' => 'low', 'status' => 'completed',
        ]);

        CoachMessage::create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Hallo!']);
        PushSubscription::create(['user_id' => $user->id, 'endpoint' => 'https://push.example/1', 'public_key' => 'k', 'auth_token' => 'a']);
        $user->wellbeingEntries()->create([
            'date' => now()->toDateString(), 'energy_level' => 7, 'mood' => 7,
            'sleep_quality' => 7, 'muscle_soreness' => 3, 'stress_level' => 3,
        ]);
        $user->forceFill(['starter_plan_viewed_at' => now()])->save();
    }

    public function test_a_new_athlete_starts_with_nothing_done(): void
    {
        $done = $this->done($this->runner());

        $this->assertSame([], array_keys(array_filter($done)));
    }

    public function test_the_checklist_reads_every_item_from_the_data(): void
    {
        $user = $this->runner();
        $this->finish($user);

        $done = $this->done($user);
        unset($done['watch']);

        $this->assertSame(array_fill_keys(array_keys($done), true), $done);
    }

    public function test_the_first_week_needs_a_completed_session_not_just_time(): void
    {
        $user = $this->runner();
        $this->finish($user);
        TrainingSession::where('user_id', $user->id)->update(['status' => 'planned']);

        $this->assertFalse($this->done($user)['first_week']);
        $this->assertFalse($this->done($user)['first_session']);
    }

    public function test_the_first_week_is_not_done_while_it_runs(): void
    {
        $user = $this->runner();
        $this->finish($user);
        // Erste Einheit in dieser Woche (Mo 16.11.): die Woche läuft noch.
        TrainingSession::where('user_id', $user->id)->update(['planned_date' => '2026-11-16']);

        $this->assertTrue($this->done($user)['first_session']);
        $this->assertFalse($this->done($user)['first_week']);
    }

    public function test_the_watch_is_optional_and_completing_awards_the_beta_badge(): void
    {
        $user = $this->runner();
        $this->finish($user);

        $card = app(StarterChecklist::class)->forUser($user->fresh());

        $this->assertTrue($card['complete']);
        $this->assertSame(100, $card['percent']);
        $this->assertNotNull($user->fresh()->beta_badge_at);
    }

    public function test_the_dashboard_shows_the_card_until_it_is_dismissed(): void
    {
        $user = $this->runner();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('starterChecklist.done', 0)
                ->where('starterChecklist.complete', false));

        // Fest oben: vor dem Ende lässt sie sich nicht schliessen.
        $this->actingAs($user)->post(route('starter-checklist.dismiss'))->assertStatus(422);

        $this->finish($user);
        $this->actingAs($user)->post(route('starter-checklist.dismiss'))->assertRedirect();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('starterChecklist', null));
        $this->assertNotNull($user->fresh()->beta_badge_at);
    }

    public function test_opening_the_plan_ticks_plan_viewed(): void
    {
        $user = $this->runner();
        $this->finish($user);
        $user->forceFill(['starter_plan_viewed_at' => null])->save();
        $event = $user->events()->first();

        $this->actingAs($user)->get(route('events.plan.show', $event))->assertOk();

        $this->assertNotNull($user->fresh()->starter_plan_viewed_at);
    }
}
