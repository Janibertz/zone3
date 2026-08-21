<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Über Strava kommt alles herein, was der Athlet aufzeichnet — die
 * Statistik zählte aber fest nur `type = 'Run'`. Wer Rad fuhr, sah seine
 * Kilometer nirgends, und die Beschriftung „Läufe" stimmte zwar, verschwieg
 * aber die halbe Woche.
 *
 * Jetzt zählen alle Sportarten mit, umschaltbar über einen Filter.
 */
class SportFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 800000;

    private function addActivity(User $user, string $type, float $km, int $minutes = 30): void
    {
        Activity::create([
            'user_id'      => $user->id,
            'strava_id'    => $this->seq++,
            'name'         => $type,
            'type'         => $type,
            'distance'     => $km * 1000,
            'moving_time'  => $minutes * 60,
            'elapsed_time' => $minutes * 60,
            'average_speed' => ($km * 1000) / ($minutes * 60),
            'start_date'   => now()->subDays(3),
        ]);
    }

    private function athlete(): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $this->addActivity($user, 'Run', 10);
        $this->addActivity($user, 'Run', 12);
        $this->addActivity($user, 'Ride', 40, 90);
        $this->addActivity($user, 'Walk', 3, 40);

        return $user;
    }

    public function test_without_a_filter_every_sport_counts(): void
    {
        $user = $this->athlete();

        $this->actingAs($user)->get(route('statistics.index'))
            ->assertInertia(fn ($page) => $page
                ->where('sport', 'all')
                ->where('totals.runs', 4)
                ->where('totals.km', 65));
    }

    public function test_the_filter_narrows_the_totals(): void
    {
        $user = $this->athlete();

        $this->actingAs($user)->get(route('statistics.index', ['sport' => 'run']))
            ->assertInertia(fn ($page) => $page
                ->where('sport', 'run')
                ->where('totals.runs', 2)
                ->where('totals.km', 22));

        $this->actingAs($user)->get(route('statistics.index', ['sport' => 'ride']))
            ->assertInertia(fn ($page) => $page
                ->where('totals.runs', 1)
                ->where('totals.km', 40));
    }

    /** Die Pace bleibt bei Läufen — über Radfahrten sagt sie nichts. */
    public function test_the_pace_trend_stays_with_runs(): void
    {
        $user = $this->athlete();

        $this->actingAs($user)->get(route('statistics.index', ['sport' => 'ride']))
            ->assertInertia(fn ($page) => $page->has('paceTrend', 2));
    }

    /** Nur angebotene Sportarten, die es auch gibt. */
    public function test_only_practised_sports_are_offered(): void
    {
        $user = $this->athlete();

        $this->actingAs($user)->get(route('statistics.index'))
            ->assertInertia(function ($page) {
                $options = collect($page->toArray()['props']['sportOptions'])->pluck('value');

                $this->assertContains('all', $options);
                $this->assertContains('run', $options);
                $this->assertContains('ride', $options);
                $this->assertContains('walk', $options);
                $this->assertNotContains('swim', $options, 'Schwimmen steht nicht im Datenbestand');
            });
    }

    /** Wer nur läuft, braucht keinen Filter. */
    public function test_a_single_sport_gets_no_filter(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->addActivity($user, 'Run', 10);
        $this->addActivity($user, 'TrailRun', 14);

        $this->actingAs($user)->get(route('statistics.index'))
            ->assertInertia(fn ($page) => $page->where('sportOptions', []));
    }

    /** Ein erfundener Wert in der URL fällt auf „alle" zurück. */
    public function test_an_unknown_sport_falls_back_to_all(): void
    {
        $user = $this->athlete();

        $this->actingAs($user)->get(route('statistics.index', ['sport' => 'quidditch']))
            ->assertInertia(fn ($page) => $page->where('sport', 'all')->where('totals.runs', 4));
    }
}
