<?php

namespace Tests\Feature;

use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\SessionContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gemeldet am 29.10.2026: in der Planliste „Tempolauf 10,7 km", in den
 * Details derselben Einheit 12 km. Die Liste las distance_km aus dem
 * Wochenbudget, die Details rechneten im Browser aus den Abschnitten nach
 * und speicherten nichts. Jetzt rechnet der Server und speichert.
 */
class SessionStepsDistanceTest extends TestCase
{
    use RefreshDatabase;

    /** Die Einheit aus dem Screenshot: 10 + 2 × (12:30 + 5) + 15 min. */
    private function thresholdSteps(): array
    {
        return [
            ['type' => 'warmup',   'label' => 'Einlaufen',     'duration_min' => 10,   'pace_target' => '4:59–5:58', 'repetitions' => null],
            ['type' => 'work',     'label' => 'Schwelle',      'duration_min' => 12.5, 'pace_target' => '4:20',      'repetitions' => 2],
            ['type' => 'rest',     'label' => 'Lockeres Traben', 'duration_min' => 5,  'pace_target' => null,        'repetitions' => 2],
            ['type' => 'cooldown', 'label' => 'Auslaufen',     'duration_min' => 15,   'pace_target' => '4:59–5:58', 'repetitions' => null],
        ];
    }

    public function test_the_distance_follows_the_steps_including_the_trot(): void
    {
        // 25 min Einlaufen/Auslaufen + 10 min Traben bei 5:28,5 = 6,39 km,
        // 25 min bei 4:20 = 5,77 km.
        $this->assertSame(12.2, SessionContentService::stepsTotalKm($this->thresholdSteps()));
    }

    public function test_a_prescribed_distance_counts_exactly(): void
    {
        $steps = [
            ['type' => 'work', 'distance_m' => 1000, 'duration_min' => 4.2, 'pace_target' => '4:10', 'repetitions' => 5],
            ['type' => 'rest', 'duration_min' => 2, 'pace_target' => null, 'repetitions' => 5],
        ];

        // Ohne Pace fuer die Pause (kein Ein-/Auslaufen) zaehlt nur die Strecke.
        $this->assertSame(5.0, SessionContentService::stepsTotalKm($steps));
    }

    public function test_steps_without_any_pace_give_no_number(): void
    {
        $this->assertNull(SessionContentService::stepsTotalKm([
            ['type' => 'work', 'duration_min' => 60, 'power_low' => 0.6, 'power_high' => 0.75],
        ]));
    }

    public function test_opening_the_details_stores_the_distance_for_the_list(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $session = TrainingSession::create([
            'user_id'      => $user->id,
            'planned_date' => now()->addDay()->toDateString(),
            'type'         => 'tempo_run',
            'title'        => 'Kontrollierte Schwelle',
            'intensity'    => 'high',
            'distance_km'  => 10.7,
            'duration_min' => 60,
            'status'       => 'planned',
            'steps'        => $this->thresholdSteps(),
        ]);

        $this->actingAs($user)
            ->getJson(route('training-sessions.steps', $session->id))
            ->assertOk()
            ->assertJsonPath('distance_km', 12.2);

        $this->assertSame(12.2, $session->fresh()->distance_km);
    }

    public function test_the_nutrition_endpoint_is_gone(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('training-sessions.nutrition-tips'));
    }
}
