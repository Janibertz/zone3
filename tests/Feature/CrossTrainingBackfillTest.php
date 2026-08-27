<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Der Bestand wird nachgezogen.
 *
 * Alles, was vor dieser Aenderung importiert wurde, liegt noch mit
 * `type = 'easy_run'` und ohne Sportart in der Datenbank. Die Migration
 * holt beides aus der verknuepften Aktivitaet nach.
 */
class CrossTrainingBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** Einen Datensatz im alten Format anlegen. */
    private function legacy(User $user, string $sport, string $type = 'easy_run'): int
    {
        $activity = Activity::create([
            'user_id' => $user->id, 'strava_id' => random_int(1000000, 9999999),
            'name' => $sport, 'type' => $sport, 'start_date' => now()->subDays(5),
            'distance' => 1500, 'moving_time' => 2400, 'elapsed_time' => 2400,
            'average_speed' => 0.625,
        ]);

        $id = DB::table('training_sessions')->insertGetId([
            'user_id' => $user->id, 'activity_id' => $activity->id,
            'planned_date' => now()->subDays(5)->toDateString(),
            'type' => $type, 'sport_type' => null,
            'title' => $sport, 'description' => '', 'intensity' => 'medium',
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function runMigration(): void
    {
        (require base_path('database/migrations/2026_08_21_140000_separate_cross_training_sessions.php'))->up();
    }

    public function test_an_old_swim_row_is_repaired(): void
    {
        $user = User::factory()->onboarded()->create();
        $id   = $this->legacy($user, 'Swim');

        $this->runMigration();

        $session = TrainingSession::find($id);
        $this->assertSame('cross_training', $session->type);
        $this->assertSame('Swim', $session->sport_type);
        $this->assertFalse($session->isRun());
    }

    public function test_an_old_ride_row_is_repaired(): void
    {
        $user = User::factory()->onboarded()->create();
        $id   = $this->legacy($user, 'Ride');

        $this->runMigration();

        $this->assertSame('cross_training', TrainingSession::find($id)->type);
    }

    /** Ein echter Lauf behaelt seinen Trainingstyp. */
    public function test_a_real_run_keeps_its_training_type(): void
    {
        $user = User::factory()->onboarded()->create();
        $id   = $this->legacy($user, 'Run', 'tempo_run');

        $this->runMigration();

        $session = TrainingSession::find($id);
        $this->assertSame('tempo_run', $session->type, 'Der Trainingstyp ist die eigentliche Information');
        $this->assertSame('Run', $session->sport_type);
        $this->assertTrue($session->isRun());
    }

    /** Krafttraining ebenso — der Typ war dort nie falsch. */
    public function test_strength_keeps_its_type(): void
    {
        $user = User::factory()->onboarded()->create();
        $id   = $this->legacy($user, 'WeightTraining', 'strength');

        $this->runMigration();

        $this->assertSame('strength', TrainingSession::find($id)->type);
    }

    /** Geplante Einheiten ohne Aktivitaet bleiben unberuehrt. */
    public function test_a_planned_session_without_an_activity_is_untouched(): void
    {
        $user = User::factory()->onboarded()->create();

        $id = DB::table('training_sessions')->insertGetId([
            'user_id' => $user->id, 'planned_date' => now()->addDay()->toDateString(),
            'type' => 'interval', 'sport_type' => null, 'title' => 'Intervalle',
            'description' => '', 'intensity' => 'high', 'status' => 'planned',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $session = TrainingSession::find($id);
        $this->assertSame('interval', $session->type);
        $this->assertNull($session->sport_type);
        $this->assertTrue($session->isRun());
    }
}
