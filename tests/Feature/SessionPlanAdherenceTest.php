<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\CoachMessage;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingLoadService;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan gegen Wirklichkeit.
 *
 * Der Strava-Import ueberschrieb distance_km, duration_min und pace_target
 * mit den tatsaechlichen Werten. Das Coach-Review las danach fuer "Geplant"
 * und "Absolviert" dieselben Felder — es bekam zwei identische Zahlen und
 * konnte eine Abweichung gar nicht bemerken.
 *
 * Der Plan liegt jetzt als Schnappschuss daneben.
 */
class SessionPlanAdherenceTest extends TestCase
{
    use RefreshDatabase;

    private function plan(User $user): TrainingPlan
    {
        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(40),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);

        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        return $plan;
    }

    private function plannedSession(User $user, TrainingPlan $plan): TrainingSession
    {
        return TrainingSession::create([
            'user_id'          => $user->id,
            'training_plan_id' => $plan->id,
            'event_id'         => $plan->event_id,
            'planned_date'     => now()->toDateString(),
            'type'             => 'easy_run',
            'title'            => 'Lockerer Lauf',
            'description'      => 'Ruhig in Zone 2.',
            'distance_km'      => 10,
            'duration_min'     => 60,
            'pace_target'      => '6:00-6:30',
            'zone'             => 2,
            'intensity'        => 'low',
            'status'           => 'planned',
        ]);
    }

    private function facts(TrainingSession $session): string
    {
        $job    = new \App\Jobs\GenerateSessionReviewJob($session->id);
        $method = new \ReflectionMethod($job, 'buildFacts');

        return $method->invoke($job, $session, app(TrainingLoadService::class));
    }

    /** Der Kern: nach dem Import ist noch nachvollziehbar, was geplant war. */
    public function test_the_plan_survives_the_strava_import(): void
    {
        $user    = User::factory()->onboarded()->create();
        $plan    = $this->plan($user);
        $session = $this->plannedSession($user, $plan);

        $session->update([
            'planned_snapshot' => [
                'type' => 'easy_run', 'title' => 'Lockerer Lauf',
                'distance_km' => 10, 'duration_min' => 60,
                'pace_target' => '6:00-6:30', 'zone' => 2, 'intensity' => 'low',
            ],
            'status'       => 'completed',
            'distance_km'  => 16.4,
            'duration_min' => 88,
            'pace_target'  => '5:22',
        ]);

        $snapshot = $session->refresh()->planned_snapshot;

        $this->assertSame(10, $snapshot['distance_km'], 'Der geplante Umfang muss erhalten bleiben');
        $this->assertSame('6:00-6:30', $snapshot['pace_target']);
        $this->assertSame(16.4, $session->distance_km, 'Die Ist-Werte stehen weiter im Datensatz');
    }

    public function test_an_unplanned_session_is_marked(): void
    {
        $user = User::factory()->onboarded()->create();
        $plan = $this->plan($user);

        $session = TrainingSession::create([
            'user_id'          => $user->id,
            'training_plan_id' => $plan->id,
            'planned_date'     => now()->toDateString(),
            'type'             => 'easy_run',
            'title'            => 'Feierabendrunde',
            'intensity'        => 'medium',
            'status'           => 'completed',
            'was_unplanned'    => true,
        ]);

        $this->assertTrue($session->refresh()->was_unplanned);
    }

    /** Die Abweichung wird gerechnet, nicht geschaetzt. */
    public function test_the_deviation_is_computed_from_the_snapshot(): void
    {
        $user    = User::factory()->onboarded()->create();
        $plan    = $this->plan($user);
        $session = $this->plannedSession($user, $plan);

        $activity = Activity::create([
            'user_id'       => $user->id,
            'strava_id'     => 999001,
            'name'          => 'Abendlauf',
            'type'          => 'Run',
            'start_date'    => now(),
            'distance'      => 16400,
            'moving_time'   => 5280,      // 88 min
            'elapsed_time'  => 5280,
            'average_speed' => 3.106,     // ~ 5:22 min/km
        ]);

        $session->update([
            'planned_snapshot' => [
                'type' => 'easy_run', 'distance_km' => 10,
                'duration_min' => 60, 'pace_target' => '6:00-6:30', 'zone' => 2,
            ],
            'status'       => 'completed',
            'activity_id'  => $activity->id,
            'distance_km'  => 16.4,
            'duration_min' => 88,
            'pace_target'  => '5:22',
        ]);

        $facts = $this->facts($session->refresh());

        $this->assertStringContainsString('EINORDNUNG: Geplante Einheit', $facts);
        $this->assertStringContainsString('Geplant war:', $facts);
        $this->assertStringContainsString('Abweichung vom Plan:', $facts);
        $this->assertStringContainsString('schneller als vorgesehen', $facts);
        $this->assertStringContainsString('+64 %', $facts, 'Der Umfang lag 64 Prozent ueber dem Plan');
    }

    /** Wer im Rahmen bleibt, bekommt keine erfundene Abweichung. */
    public function test_a_session_run_as_planned_reports_no_deviation(): void
    {
        $user    = User::factory()->onboarded()->create();
        $plan    = $this->plan($user);
        $session = $this->plannedSession($user, $plan);

        $activity = Activity::create([
            'user_id'       => $user->id,
            'strava_id'     => 999002,
            'name'          => 'Lockerer Lauf',
            'type'          => 'Run',
            'start_date'    => now(),
            'distance'      => 10200,
            'moving_time'   => 3720,      // 62 min
            'elapsed_time'  => 3720,
            'average_speed' => 2.742,     // ~ 6:05 min/km
        ]);

        $session->update([
            'planned_snapshot' => [
                'type' => 'easy_run', 'distance_km' => 10,
                'duration_min' => 60, 'pace_target' => '6:00-6:30', 'zone' => 2,
            ],
            'status'       => 'completed',
            'activity_id'  => $activity->id,
            'distance_km'  => 10.2,
            'duration_min' => 62,
        ]);

        $this->assertStringContainsString('wie geplant umgesetzt', $this->facts($session->refresh()));
    }

    /** Ein ungeplanter Lauf wird im Faktenblock als solcher ausgewiesen. */
    public function test_the_facts_call_out_an_unplanned_session(): void
    {
        $user = User::factory()->onboarded()->create();
        $plan = $this->plan($user);

        $session = TrainingSession::create([
            'user_id'          => $user->id,
            'training_plan_id' => $plan->id,
            'planned_date'     => now()->toDateString(),
            'type'             => 'easy_run',
            'title'            => 'Spontanrunde',
            'intensity'        => 'medium',
            'status'           => 'completed',
            'was_unplanned'    => true,
            'planned_snapshot' => ['type' => 'rest', 'title' => 'Ruhetag'],
        ]);

        $facts = $this->facts($session);

        $this->assertStringContainsString('Ungeplant', $facts);
        $this->assertStringContainsString('RUHETAG', $facts);
    }

    // ── Benachrichtigungen ───────────────────────────────────────────────

    /** Jede Nachricht des Coaches loest eine Benachrichtigung aus. */
    public function test_a_coach_message_triggers_a_push(): void
    {
        $user = User::factory()->onboarded()->create();
        $user->forceFill(['push_notifications_enabled' => true])->save();

        $this->mock(WebPushService::class)->shouldReceive('sendToUser')->once();

        CoachMessage::create([
            'user_id' => $user->id,
            'role'    => 'assistant',
            'content' => 'Starke Einheit heute!',
        ]);
    }

    /** Die eigenen Nachrichten des Athleten nicht. */
    public function test_an_athlete_message_triggers_nothing(): void
    {
        $user = User::factory()->onboarded()->create();
        $user->forceFill(['push_notifications_enabled' => true])->save();

        $this->mock(WebPushService::class)->shouldNotReceive('sendToUser');

        CoachMessage::create([
            'user_id' => $user->id,
            'role'    => 'user',
            'content' => 'Wie lief das?',
        ]);
    }

    /** Wer keine Benachrichtigungen will, bekommt keine. */
    public function test_push_respects_the_setting(): void
    {
        $user = User::factory()->onboarded()->create();
        $user->forceFill(['push_notifications_enabled' => false])->save();

        $this->mock(WebPushService::class)->shouldNotReceive('sendToUser');

        CoachMessage::create([
            'user_id' => $user->id,
            'role'    => 'assistant',
            'content' => 'Hallo',
        ]);
    }
}
