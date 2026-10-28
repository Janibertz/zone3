<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die Aktivitätenliste nach dem Redesign (28.10.2026): nach Sportart-Gruppe
 * gefiltert, nach Wochen gruppiert, Seiten werden angehängt.
 */
class ActivityListTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 900000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 12:00'); // ein Mittwoch
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function add(User $user, string $type, float $km, string $date): void
    {
        Activity::create([
            'user_id' => $user->id, 'strava_id' => $this->seq++, 'name' => $type, 'type' => $type,
            'distance' => $km * 1000, 'moving_time' => 1800, 'elapsed_time' => 1800,
            'average_speed' => $km * 1000 / 1800, 'start_date' => Carbon::parse($date),
        ]);
    }

    /** Der alte Filter `type=Run` traf weder Trail- noch Laufbandläufe. */
    public function test_the_run_filter_covers_every_kind_of_run(): void
    {
        $user = User::factory()->onboarded()->create();
        $this->add($user, 'Run', 10, '2026-10-27 07:00');
        $this->add($user, 'TrailRun', 12, '2026-10-26 07:00');
        $this->add($user, 'Ride', 40, '2026-10-26 18:00');

        $this->actingAs($user)->get(route('activities.index', ['sport' => 'run']))
            ->assertInertia(fn ($page) => $page
                ->component('Activities')
                ->has('activities', 2)
                ->where('filters.sport', 'run'));
    }

    /**
     * Die Wochensumme kommt vom Server und zählt die ganze Woche — auch
     * Aktivitäten, die erst auf der nächsten Seite stünden.
     */
    public function test_week_totals_count_the_whole_week(): void
    {
        $user = User::factory()->onboarded()->create();
        for ($i = 0; $i < 31; $i++) {
            $this->add($user, 'Run', 5, '2026-10-26 06:00'); // alle am Montag dieser Woche
        }
        $this->add($user, 'Run', 8, '2026-10-20 06:00');

        $this->actingAs($user)->get(route('activities.index'))
            ->assertInertia(fn ($page) => $page
                ->has('activities', 30)
                ->where('pagination.last_page', 2)
                ->where('weekTotals.2026-10-26.count', 31)
                ->where('weekTotals.2026-10-26.km', 155));
    }
}
