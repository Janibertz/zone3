<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use App\Services\PlanContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gemeldet: „Ich habe gestern über meinen Coach einen Longrun auf Sonntag
 * mit 25 km gesetzt. Ich möchte nicht, dass diese Session durch die
 * Neuberechnung wieder überschrieben wird."
 *
 * Sie wäre es geworden. Beide Plan-Jobs räumen vor dem Schreiben auf:
 *
 *   TrainingSession::whereIn('training_plan_id', $ids)
 *       ->where('status', 'planned')->delete();
 *
 * Alles Geplante flog raus, auch das ausdrücklich Bestellte — ersetzt durch
 * den Wert aus der Longrun-Leiter. Was der Athlet sagt, wiegt aber mehr als
 * das, was das Modell sich als Nächstes ausdenkt.
 */
class PinnedSessionTest extends TestCase
{
    use RefreshDatabase;

    private function athlete(): array
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Berlin Marathon',
            'event_date'          => now()->addDays(37),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);

        $plan = TrainingPlan::create([
            'user_id'   => $user->id,
            'event_id'  => $event->id,
            'sessions'  => [],
            'is_active' => true,
        ]);

        return [$user, $event, $plan];
    }

    private function plannedSession(User $user, Event $event, TrainingPlan $plan, string $date, bool $pinned = false): int
    {
        return DB::table('training_sessions')->insertGetId([
            'user_id'          => $user->id,
            'training_plan_id' => $plan->id,
            'event_id'         => $event->id,
            'planned_date'     => $date,
            'type'             => 'long_run',
            'title'            => $pinned ? 'Longrun 25 km' : 'Langer Lauf',
            'description'      => '',
            'distance_km'      => $pinned ? 25 : 18,
            'duration_min'     => $pinned ? 145 : 100,
            'intensity'        => 'medium',
            'status'           => 'planned',
            'sort_order'       => 0,
            'pinned_at'        => $pinned ? now() : null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // ── Der gemeldete Fall ───────────────────────────────────────────────

    /** Der Coach markiert, was er auf Ansage des Athleten setzt. */
    public function test_the_coach_pins_what_it_sets(): void
    {
        [$user, $event, $plan] = $this->athlete();
        $sunday = now()->addDays(2)->toDateString();

        $method = new \ReflectionMethod(CoachChatService::class, 'executeCoachTool');
        $method->setAccessible(true);
        $method->invoke(app(CoachChatService::class), $user->fresh(), 'modify_training_session', [
            'date' => $sunday, 'type' => 'long_run', 'title' => 'Longrun 25 km', 'distance_km' => 25,
        ]);

        $session = TrainingSession::where('user_id', $user->id)->whereDate('planned_date', $sunday)->first();

        $this->assertNotNull($session->pinned_at, 'Was über den Chat kommt, hat der Athlet bestellt');
    }

    /** Und der Planer räumt sie nicht mehr weg. */
    public function test_a_pinned_session_survives_the_cleanup(): void
    {
        [$user, $event, $plan] = $this->athlete();

        $pinnedId = $this->plannedSession($user, $event, $plan, now()->addDays(2)->toDateString(), pinned: true);
        $normalId = $this->plannedSession($user, $event, $plan, now()->addDays(3)->toDateString());

        // Genau die Abfrage, mit der beide Jobs vor dem Schreiben aufräumen.
        TrainingSession::whereIn('training_plan_id', [$plan->id])
            ->where('status', 'planned')
            ->whereNull('pinned_at')
            ->delete();

        $this->assertNotNull(TrainingSession::find($pinnedId), 'Die gesetzte Einheit muss bleiben');
        $this->assertNull(TrainingSession::find($normalId),   'Die geplante darf gehen');
    }

    // ── Der Planer plant darum herum ─────────────────────────────────────

    /**
     * Ein gesetzter Tag ist für das Gerüst vergeben — sonst legt der Planer
     * eine zweite Einheit daneben, und der Validator wirft eine davon weg.
     */
    public function test_a_pinned_day_is_blocked_in_the_skeleton(): void
    {
        [$user, $event, $plan] = $this->athlete();
        $user->runnerProfile()->create([
            'threshold_speed'     => 4.4,
            'weekly_availability' => collect(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'])
                ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => 120]])->all(),
        ]);

        $sunday = now()->addDays(2)->toDateString();
        $this->plannedSession($user, $event, $plan, $sunday, pinned: true);

        $context = app(PlanContextBuilder::class)->build($user->refresh(), $event);

        $this->assertContains($sunday, $context->blockedDates());
        $this->assertTrue($context->skeleton['days'][$sunday]['finalized']);
        $this->assertEmpty($context->skeleton['days'][$sunday]['slots'], 'Für einen gesetzten Tag plant das Gerüst nichts');
    }

    /** Und der Prompt sagt dem Modell, warum der Tag fehlt. */
    public function test_the_prompt_names_the_pinned_session(): void
    {
        [$user, $event, $plan] = $this->athlete();
        $user->runnerProfile()->create(['threshold_speed' => 4.4]);

        $sunday = now()->addDays(2)->toDateString();
        $this->plannedSession($user, $event, $plan, $sunday, pinned: true);

        $context = app(PlanContextBuilder::class)->build($user->refresh(), $event);

        $this->assertCount(1, $context->pinnedSessions);
        $this->assertSame('Longrun 25 km', $context->pinnedSessions[0]['title']);
        $this->assertEquals(25, $context->pinnedSessions[0]['distance_km']);
    }

    /** Vergangene Tage sind kein Thema mehr — die gehören der Historie. */
    public function test_past_pinned_sessions_are_not_carried_along(): void
    {
        [$user, $event, $plan] = $this->athlete();
        $this->plannedSession($user, $event, $plan, now()->subDays(3)->toDateString(), pinned: true);

        $context = app(PlanContextBuilder::class)->build($user->refresh(), $event);

        $this->assertEmpty($context->pinnedSessions);
    }
}
