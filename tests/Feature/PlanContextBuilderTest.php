<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GarminDailyMetric;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\PlanContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Kontext wurde vorher in beiden Plan-Jobs getrennt zusammengebaut —
 * ~150 kopierte Zeilen, die bereits auseinandergelaufen waren. Diese Tests
 * halten fest, was der gemeinsame Aufbau liefert.
 */
class PlanContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['onboarding_completed_at' => now()]);
    }

    private function event(User $user, array $attrs = []): Event
    {
        return Event::create(array_merge([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(60),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ], $attrs));
    }

    private function build(User $user, Event $event): \App\Services\PlanContext
    {
        return app(PlanContextBuilder::class)->build($user, $event);
    }

    public function test_the_window_never_exceeds_the_planning_horizon(): void
    {
        $user    = $this->user();
        $context = $this->build($user, $this->event($user));

        $days = (int) $context->windowFrom->diffInDays($context->windowTo) + 1;
        $this->assertSame(Event::PLAN_HORIZON_DAYS, $days);
    }

    /** Liegt das Rennen näher als das Fenster, endet der Plan am Renntag. */
    public function test_a_near_race_shortens_the_window(): void
    {
        $user    = $this->user();
        $event   = $this->event($user, ['event_date' => now()->addDays(4)]);
        $context = $this->build($user, $event);

        $this->assertSame($event->event_date->format('Y-m-d'), $context->windowTo->format('Y-m-d'));
    }

    /**
     * Das Fenster für andere Rennen lag fest bei zehn Tagen, geplant wird
     * aber über vierzehn. Rennen an Tag 11 bis 14 fehlten deshalb, und der
     * Athlet bekam an seinem Renntag Training.
     */
    public function test_other_races_late_in_the_window_are_included(): void
    {
        $user = $this->user();
        $main = $this->event($user);

        $this->event($user, [
            'name'          => 'Volkslauf',
            'event_date'    => now()->addDays(12),
            'race_distance' => '10km',
            'priority'      => 'C',
        ]);

        $context = $this->build($user, $main);

        $this->assertCount(1, $context->otherEvents);
        $this->assertSame('Volkslauf', $context->otherEvents[0]['name']);
    }

    public function test_races_beyond_the_window_are_left_out(): void
    {
        $user = $this->user();
        $main = $this->event($user);

        $this->event($user, ['name' => 'Zu spät', 'event_date' => now()->addDays(30)]);

        $this->assertEmpty($this->build($user, $main)->otherEvents);
    }

    /** Das Anschlussziel darf ausdrücklich hinter dem Fenster liegen. */
    public function test_the_follow_up_goal_may_be_far_away(): void
    {
        $user = $this->user();
        $main = $this->event($user);

        $this->event($user, [
            'name'       => 'Herbstmarathon',
            'event_date' => now()->addDays(200),
            'priority'   => 'A',
        ]);

        $this->assertSame('Herbstmarathon', $this->build($user, $main)->followUpGoal['name']);
    }

    public function test_the_skeleton_is_attached(): void
    {
        $user = $this->user();
        $user->runnerProfile()->create([
            'weekly_availability' => collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
                ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => 90]])
                ->all(),
        ]);

        $context = $this->build($user->refresh(), $this->event($user));

        $this->assertNotNull($context->skeleton);
        $this->assertNotEmpty($context->skeleton['weeks']);
    }

    /** Ohne verbundene Uhr darf kein Garmin-Abschnitt entstehen. */
    public function test_no_garmin_section_without_a_connection(): void
    {
        $user = $this->user();

        $this->assertNull($this->build($user, $this->event($user))->garminText);
    }

    public function test_garmin_values_reach_the_context(): void
    {
        $user = $this->user();
        $user->forceFill(['garmin_session' => 'token'])->save();

        foreach (range(0, 20) as $i) {
            GarminDailyMetric::create([
                'user_id'     => $user->id,
                'date'        => now()->subDays($i)->toDateString(),
                'hrv'         => 50,
                'resting_hr'  => 48,
                'sleep_hours' => 7.5,
            ]);
        }

        $text = $this->build($user->refresh(), $this->event($user))->garminText;

        $this->assertNotNull($text);
        $this->assertStringContainsString('HRV', $text);
        $this->assertStringContainsString('Ruhepuls', $text);
    }

    /** Ohne Werte muss das ausdrücklich dastehen, sonst liest es sich wie "alles gut". */
    public function test_a_connected_watch_without_data_says_so(): void
    {
        $user = $this->user();
        $user->forceFill(['garmin_session' => 'token'])->save();

        $text = $this->build($user->refresh(), $this->event($user))->garminText;

        $this->assertStringContainsString('keine Daten', $text);
    }

    /** Abgeschlossene Tage gehören dem Athleten und dürfen nicht überplant werden. */
    public function test_finalized_days_are_reported(): void
    {
        $user  = $this->user();
        $event = $this->event($user);

        // Einheiten haengen immer an einem Plan — darueber findet sie der Builder.
        $plan = \App\Models\TrainingPlan::create([
            'user_id'  => $user->id,
            'event_id' => $event->id,
            'sessions' => [],
        ]);

        TrainingSession::create([
            'user_id'          => $user->id,
            'event_id'         => $event->id,
            'training_plan_id' => $plan->id,
            'planned_date'     => now()->addDay()->toDateString(),
            'type'             => 'easy_run',
            'title'            => 'Schon gelaufen',
            'intensity'        => 'low',
            'status'           => 'completed',
        ]);

        $context = $this->build($user, $event);

        $this->assertNotEmpty($context->finalizedSessions);
        $this->assertContains(now()->addDay()->toDateString(), $context->finalizedDates());
    }
}
