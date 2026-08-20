<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Rennzeit kommt aus Strava.
 *
 * Der Lauf wird ohnehin importiert, und die Renn-Analyse ordnet ihm längst
 * die richtige Aktivität zu — nur das Ergebnisfeld daneben blieb leer, bis
 * jemand die Zeit von Hand abtippte. Wer das vergaß, für den hatte das
 * Rennen nie stattgefunden: die nächste Planung liest genau dieses Feld.
 */
class RaceResultAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private function pastEvent(User $user, string $distance = 'marathon', array $attrs = []): Event
    {
        return Event::create(array_merge([
            'user_id'             => $user->id,
            'name'                => 'Stadtmarathon',
            'event_date'          => now()->subDays(2),
            'race_distance'       => $distance,
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ], $attrs));
    }

    private function race(User $user, Event $event, int $meters, int $elapsed, int $moving, string $name = 'Marathon'): Activity
    {
        return Activity::create([
            'user_id'       => $user->id,
            'strava_id'     => random_int(100000, 999999),
            'name'          => $name,
            'type'          => 'Run',
            'start_date'    => $event->event_date,
            'distance'      => $meters,
            'moving_time'   => $moving,
            'elapsed_time'  => $elapsed,
            'average_speed' => $meters / max($moving, 1),
        ]);
    }

    public function test_the_time_is_taken_from_the_matching_activity(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->pastEvent($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        // 3:24:15 brutto, 3:23:00 netto — gezaehlt wird brutto.
        $this->race($user, $event, 42195, 12255, 12180);

        $this->actingAs($user)->get(route('events.plan.show', $event))->assertOk();

        $plan->refresh();
        $this->assertSame(3, $plan->actual_time_hours);
        $this->assertSame(24, $plan->actual_time_minutes);
    }

    public function test_the_page_says_where_the_number_came_from(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->pastEvent($user);
        TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        $this->race($user, $event, 42195, 12255, 12180, 'Stadtmarathon 2026');

        $this->actingAs($user)
            ->get(route('events.plan.show', $event))
            ->assertInertia(fn ($page) => $page
                ->where('resultSource.time', '3:24:15')
                ->where('resultSource.activity', 'Stadtmarathon 2026'));
    }

    /** Eine von Hand eingetragene Zeit ist die offizielle — sie bleibt stehen. */
    public function test_a_manual_time_is_never_overwritten(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->pastEvent($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->update(['actual_time_hours' => 3, 'actual_time_minutes' => 19]);

        $this->race($user, $event, 42195, 12255, 12180);

        $this->actingAs($user)
            ->get(route('events.plan.show', $event))
            ->assertInertia(fn ($page) => $page->where('resultSource', null));

        $this->assertSame(19, $plan->refresh()->actual_time_minutes);
    }

    /** Von mehreren Läufen am Renntag zählt der mit der passenden Distanz. */
    public function test_the_closest_distance_wins(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->pastEvent($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        $this->race($user, $event, 3000, 1200, 1200, 'Auslaufen');       // 20 min
        $this->race($user, $event, 42100, 12600, 12500, 'Der Marathon'); // 3:30:00

        $this->actingAs($user)->get(route('events.plan.show', $event))->assertOk();

        $this->assertSame(3, $plan->refresh()->actual_time_hours);
        $this->assertSame(30, $plan->actual_time_minutes);
    }

    public function test_nothing_happens_without_a_matching_run(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->pastEvent($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        $this->actingAs($user)
            ->get(route('events.plan.show', $event))
            ->assertInertia(fn ($page) => $page->where('resultSource', null));

        $this->assertNull($plan->refresh()->actual_time_hours);
    }

    /** Solange das Rennen noch aussteht, gibt es nichts zu übernehmen. */
    public function test_nothing_happens_before_the_race(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->pastEvent($user, attrs: ['event_date' => now()->addDays(5)]);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        $this->race($user, $event, 42195, 12255, 12180);

        $this->actingAs($user)->get(route('events.plan.show', $event))->assertOk();

        $this->assertNull($plan->refresh()->actual_time_hours);
    }

    /** Ein Backyard besteht aus vielen Yards — der längste ist nicht das Ergebnis. */
    public function test_a_backyard_is_left_alone(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->pastEvent($user, 'backyard_ultra', [
            'name'         => 'Backyard',
            'target_yards' => 20,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        $this->race($user, $event, 6706, 3300, 3300, 'Yard 14');

        $this->actingAs($user)->get(route('events.plan.show', $event))->assertOk();

        $this->assertNull($plan->refresh()->actual_time_hours);
    }
}
