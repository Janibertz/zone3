<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use App\Services\Garmin\GarminWorkoutBuilder;
use App\Services\Run\SessionStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gemeldet am 22.11.2026: „Longrun von 15 km im Hauptteil, das Warmup will
 * ich durch die Lap-Taste beenden, das Cooldown ebenfalls." Der Coach legte
 * 15 km an und sagte das offene Ein- und Auslaufen zu — eingetragen war nur
 * der Hauptteil, denn so etwas gab es gar nicht.
 */
class OpenWarmupCooldownTest extends TestCase
{
    use RefreshDatabase;

    private function athlete(): User
    {
        $user = User::factory()->create();
        $user->runnerProfile()->create(['threshold_speed' => 4 + 22 / 60]);
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => now()->addDays(120),
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ]);
        TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        return $user->fresh();
    }

    private function runTool(User $user, string $tool, array $args): array
    {
        $method = new \ReflectionMethod(CoachChatService::class, 'executeCoachTool');
        $method->setAccessible(true);

        return $method->invoke(app(CoachChatService::class), $user->fresh(), $tool, $args);
    }

    private function longRun(User $user, array $extra = []): array
    {
        return $this->runTool($user, 'create_training_session', [
            'date' => now()->toDateString(), 'type' => 'long_run', 'title' => 'Longrun',
            'distance_km' => 15, 'workout' => 'lang_locker',
        ] + $extra);
    }

    public function test_the_long_run_gets_open_warmup_and_cooldown(): void
    {
        $user   = $this->athlete();
        $result = $this->longRun($user, ['open_warmup' => true, 'open_cooldown' => true]);

        $steps = TrainingSession::where('user_id', $user->id)->first()->steps;
        $this->assertCount(3, $steps);
        $this->assertSame(['warmup', 'work', 'cooldown'], array_column($steps, 'type'));
        $this->assertTrue($steps[0]['open']);
        $this->assertTrue($steps[2]['open']);
        $this->assertSame(15000, $steps[1]['distance_m']);
        $this->assertStringContainsString('Lap-Taste', $result['message']);
    }

    /** Jan: gezählt werden nur die tatsächlichen Kilometer und Minuten. */
    public function test_open_parts_do_not_count_towards_the_totals(): void
    {
        $user = $this->athlete();
        $this->longRun($user, ['open_warmup' => true, 'open_cooldown' => true]);
        $open = TrainingSession::where('user_id', $user->id)->first();

        $other = $this->athlete();
        $this->longRun($other);
        $plain = TrainingSession::where('user_id', $other->id)->first();

        $this->assertEquals($plain->distance_km, $open->distance_km);
        $this->assertSame($plain->duration_min, $open->duration_min);
    }

    public function test_an_interval_session_replaces_its_fixed_warmup(): void
    {
        $user = $this->athlete();
        $this->runTool($user, 'create_training_session', [
            'date' => now()->toDateString(), 'type' => 'interval', 'duration_min' => 60,
            'workout' => 'vo2_1000', 'open_warmup' => true,
        ]);

        $steps = TrainingSession::where('user_id', $user->id)->first()->steps;
        $warm  = array_values(array_filter($steps, fn ($s) => $s['type'] === 'warmup'));
        $this->assertCount(1, $warm);
        $this->assertTrue($warm[0]['open']);
        $this->assertEmpty(end($steps)['open'] ?? null, 'das Auslaufen bleibt fest');
    }

    public function test_garmin_ends_open_parts_with_the_lap_button(): void
    {
        $user = $this->athlete();
        $this->longRun($user, ['open_warmup' => true, 'open_cooldown' => true]);
        $session = TrainingSession::where('user_id', $user->id)->first();

        $workout = app(GarminWorkoutBuilder::class)->forSession($session);
        $steps   = $workout['segments'][0]['steps'] ?? $workout['steps'] ?? [];
        $this->assertNotEmpty($steps);
        $this->assertSame('OPEN', $steps[0]['durationType']);
        $this->assertSame('WARMUP', $steps[0]['intensity']);
        $this->assertSame('OPEN', end($steps)['durationType']);
    }

    public function test_a_later_change_keeps_the_open_ends(): void
    {
        $user = $this->athlete();
        $this->longRun($user, ['open_warmup' => true, 'open_cooldown' => true]);

        $this->runTool($user, 'modify_training_session', [
            'date' => now()->toDateString(), 'type' => 'long_run', 'distance_km' => 18, 'workout' => 'lang_locker',
        ]);

        $ends = SessionStructure::openEnds(TrainingSession::where('user_id', $user->id)->first()->steps);
        $this->assertSame(['warmup' => true, 'cooldown' => true], $ends);
    }

    public function test_false_takes_an_open_end_back(): void
    {
        $user = $this->athlete();
        $this->longRun($user, ['open_warmup' => true, 'open_cooldown' => true]);

        $this->runTool($user, 'modify_training_session', [
            'date' => now()->toDateString(), 'type' => 'long_run', 'distance_km' => 15,
            'workout' => 'lang_locker', 'open_warmup' => false,
        ]);

        $ends = SessionStructure::openEnds(TrainingSession::where('user_id', $user->id)->first()->steps);
        $this->assertSame(['warmup' => false, 'cooldown' => true], $ends);
    }

    public function test_an_accepted_proposal_keeps_the_open_ends(): void
    {
        $user = $this->athlete();
        $this->longRun($user);

        $this->runTool($user, 'propose_session_change', [
            'date' => now()->toDateString(), 'reason' => 'Freier', 'type' => 'long_run', 'distance_km' => 15,
            'workout' => 'lang_locker', 'open_warmup' => true, 'open_cooldown' => true,
        ]);
        \App\Models\SessionRecommendation::where('user_id', $user->id)->latest('id')->first()->accept();

        $ends = SessionStructure::openEnds(TrainingSession::where('user_id', $user->id)->first()->steps);
        $this->assertSame(['warmup' => true, 'cooldown' => true], $ends);
    }

    public function test_the_tool_answer_names_what_was_really_entered(): void
    {
        $user   = $this->athlete();
        $result = $this->longRun($user);

        $this->assertStringContainsString('Eingetragener Ablauf', $result['message']);
        $this->assertStringNotContainsString('Lap-Taste', $result['message']);
    }
}
