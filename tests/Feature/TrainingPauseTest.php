<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\AI\CoachChatService;
use App\Services\PlanContextBuilder;
use App\Services\ReturnToRunService;
use App\Services\TelegramBriefing;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Krank ist krank, Pause ist Pause.
 *
 * Gemeldet am 25.10.2026: Wellbeing „krank", Notiz „Weisheitszahn-OP" —
 * und die Tagesempfehlung lautete „Sehr lockerer Wiedereinstieg, 3,5 km".
 * Im Coach-Chat „zwei Wochen Laufpause" gesagt, der Coach verstand es und
 * merkte es sich als Notiz; die Empfehlung blieb trotzdem.
 *
 * Drei Ursachen: der Empfehlungs-Prompt sah weder „krank" noch die Notiz,
 * die Wiedereinstiegs-Erkennung meldete am Tag der OP Stufe 1, und eine
 * Notiz wirkt auf nichts. Diese Tests halten fest, dass beides jetzt ohne
 * KI entschieden wird.
 */
class TrainingPauseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function athlete(): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now(), 'name' => 'Jan Tester']);
        RunnerProfile::create(['user_id' => $user->id, 'threshold_speed' => 4.5]);

        return $user->refresh();
    }

    private function wellbeing(User $user, bool $sick = false, ?string $notes = null): WellbeingEntry
    {
        return WellbeingEntry::create([
            'user_id' => $user->id, 'date' => now()->toDateString(),
            'energy_level' => 5, 'mood' => 5, 'sleep_quality' => 5, 'muscle_soreness' => 3, 'stress_level' => 4,
            'is_sick' => $sick, 'is_injured' => false, 'notes' => $notes,
        ]);
    }

    private function runTool(User $user, string $tool, array $args): array
    {
        $method = new \ReflectionMethod(CoachChatService::class, 'executeCoachTool');

        return $method->invoke(app(CoachChatService::class), $user->fresh(), $tool, $args);
    }

    private function plannedOn(User $user, string $date): int
    {
        // Über den Query Builder, damit planned_date wie unter MySQL nur ein Datum ist.
        return DB::table('training_sessions')->insertGetId([
            'user_id' => $user->id, 'planned_date' => $date, 'type' => 'easy_run', 'title' => 'Lockerer Lauf',
            'description' => '', 'intensity' => 'low', 'status' => 'planned', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Krank ────────────────────────────────────────────────────────────

    /** Kein Modellaufruf: Http::preventStrayRequests() würde ihn abbrechen. */
    public function test_a_sick_check_in_gets_a_rest_day_without_asking_the_model(): void
    {
        $user = $this->athlete();
        $this->wellbeing($user, sick: true, notes: 'Weisheitszahn-OP');

        $rec = $this->actingAs($user)->getJson(route('ai.recommendation.today'))->assertOk()->json('recommendation');

        $this->assertSame('rest', $rec['type']);
        $this->assertSame(0, $rec['distance_km']);
    }

    /** Auch eine schon gespeicherte Laufempfehlung von heute früh zählt nicht mehr. */
    public function test_a_cached_run_does_not_survive_a_sick_check_in(): void
    {
        $user  = $this->athlete();
        $entry = $this->wellbeing($user, sick: true);
        $user->runnerProfile->update([
            'today_recommendation' => json_encode(['type' => 'easy_run', 'title' => 'Sehr lockerer Wiedereinstieg']),
            'recommendation_date' => now()->toDateString(), 'recommendation_wellbeing_id' => $entry->id,
        ]);

        $this->actingAs($user)->getJson(route('ai.recommendation.today'))
            ->assertJsonPath('recommendation.type', 'rest');
    }

    /** Am Tag der Krankmeldung steigt niemand wieder ein. */
    public function test_no_comeback_step_on_a_sick_day(): void
    {
        $user = $this->athlete();
        foreach ([3, 5, 7, 9] as $i => $daysAgo) {
            $user->activities()->create([
                'strava_id' => 700 + $i, 'name' => 'Lauf', 'type' => 'Run', 'distance' => 8000,
                'moving_time' => 2700, 'elapsed_time' => 2700, 'start_date' => now()->subDays($daysAgo),
            ]);
        }
        $this->wellbeing($user, sick: true);

        $this->assertNull(app(ReturnToRunService::class)->statusFor($user->refresh()));
    }

    // ── Pause aus dem Coach-Chat ─────────────────────────────────────────

    public function test_the_coach_pauses_training_and_skips_the_sessions(): void
    {
        $user   = $this->athlete();
        $inside = $this->plannedOn($user, '2026-10-30');
        $after  = $this->plannedOn($user, '2026-11-09');

        $result = $this->runTool($user, 'pause_training', ['until' => '2026-11-08', 'reason' => 'Weisheitszahn-OP']);

        $this->assertSame('training_paused', $result['action']['type']);
        $this->assertSame('skipped', TrainingSession::find($inside)->status);
        $this->assertSame('planned', TrainingSession::find($after)->status, 'Nach der Pause bleibt der Plan stehen');

        $profile = $user->runnerProfile->fresh();
        $this->assertTrue($profile->isPausedOn('2026-11-08'));
        $this->assertFalse($profile->isPausedOn('2026-11-09'));
    }

    /** Während der Pause gibt es keine Laufempfehlung — auch bei bestem Wellbeing. */
    public function test_a_paused_athlete_gets_rest_even_when_feeling_fine(): void
    {
        $user = $this->athlete();
        $this->runTool($user, 'pause_training', ['until' => '2026-11-08', 'reason' => 'Weisheitszahn-OP']);
        $this->wellbeing($user);

        $rec = $this->actingAs($user)->getJson(route('ai.recommendation.today'))->json('recommendation');

        $this->assertSame('rest', $rec['type']);
        $this->assertStringContainsString('Trainingspause bis 08.11.', $rec['title']);
        $this->assertStringContainsString('Weisheitszahn-OP', $rec['title']);
    }

    /** Die nächste Neuberechnung darf keine Einheiten in die Pause schreiben. */
    public function test_pause_days_are_blocked_for_the_plan(): void
    {
        $user  = $this->athlete();
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Halbmarathon Hannover', 'event_date' => now()->addDays(120),
            'race_distance' => 'half_marathon', 'priority' => 'A', 'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
        $this->runTool($user, 'pause_training', ['until' => '2026-10-27']);

        $context = app(PlanContextBuilder::class)->build($user->refresh(), $event);

        $this->assertFalse($context->availabilityOverrides['2026-10-25']['available']);
        $this->assertFalse($context->availabilityOverrides['2026-10-27']['available']);
        $this->assertArrayNotHasKey('2026-10-28', $context->availabilityOverrides);
    }

    public function test_ending_the_pause_early(): void
    {
        $user = $this->athlete();
        $this->runTool($user, 'pause_training', ['until' => '2026-11-08']);

        $this->runTool($user, 'end_training_pause', []);

        $this->assertFalse($user->runnerProfile->fresh()->isPausedOn());
    }

    public function test_a_pause_in_the_past_is_refused(): void
    {
        $user = $this->athlete();

        $result = $this->runTool($user, 'pause_training', ['until' => '2026-10-20']);

        $this->assertNull($result['action']);
        $this->assertNull($user->runnerProfile->fresh()->training_paused_until);
    }

    public function test_the_telegram_briefing_names_the_pause(): void
    {
        $user = $this->athlete();
        $this->runTool($user, 'pause_training', ['until' => '2026-11-08', 'reason' => 'Weisheitszahn-OP']);

        $text = app(TelegramBriefing::class)->today($user->refresh(), morning: true);

        $this->assertStringContainsString('Trainingspause bis 08.11. (Weisheitszahn-OP)', $text);
    }
}
