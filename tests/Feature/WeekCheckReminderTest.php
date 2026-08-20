<?php

namespace Tests\Feature;

use App\Http\Controllers\WeekAvailabilityController;
use App\Models\Event;
use App\Models\PushSubscription;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Erinnerung an die Wochenabfrage.
 *
 * Die Abfrage gab es, gesehen hat sie nur, wer sonntags von selbst das
 * Dashboard öffnete. Der Command holt die Leute — aber nur die, bei denen
 * die Abfrage noch aussteht und bei denen eine Antwort etwas bewirkt.
 */
class WeekCheckReminderTest extends TestCase
{
    use RefreshDatabase;

    private function athlete(bool $withPlan = true, bool $withPush = true): User
    {
        $user = User::factory()->onboarded()->create(['push_notifications_enabled' => true]);
        RunnerProfile::create(['user_id' => $user->id]);

        if ($withPush) {
            PushSubscription::create([
                'user_id'    => $user->id,
                'endpoint'   => 'https://push.example/' . $user->id,
                'public_key' => 'k',
                'auth_token' => 'a',
            ]);
        }

        if ($withPlan) {
            $event = Event::create([
                'user_id'             => $user->id,
                'name'                => 'Zielrennen',
                'event_date'          => now()->addDays(40),
                'race_distance'       => '10km',
                'priority'            => 'A',
                'target_time_hours'   => 0,
                'target_time_minutes' => 45,
            ]);
            TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []])
                ->forceFill(['is_active' => true])->save();
        }

        return $user;
    }

    /** Ein Sonntag im Testkalender — an anderen Tagen steht nichts an. */
    private function onSunday(): void
    {
        $this->travelTo(now()->next(\Carbon\Carbon::SUNDAY)->setTime(18, 0));
    }

    public function test_a_reminder_goes_out_on_sunday(): void
    {
        $this->onSunday();
        $user = $this->athlete();

        $this->mock(WebPushService::class)
            ->shouldReceive('sendToUser')
            ->once()
            ->withArgs(fn ($u, $title, $body, $url) => $u->id === $user->id && $url === '/dashboard');

        $this->artisan('push:week-check')->assertSuccessful();
    }

    /** Mitten in der Woche geht nichts raus. */
    public function test_nothing_goes_out_on_a_wednesday(): void
    {
        $this->travelTo(now()->next(\Carbon\Carbon::WEDNESDAY)->setTime(18, 0));
        $this->athlete();

        $this->mock(WebPushService::class)->shouldNotReceive('sendToUser');

        $this->artisan('push:week-check')->assertSuccessful();
    }

    /** Wer die Woche schon bestätigt hat, wird nicht noch einmal gefragt. */
    public function test_a_confirmed_week_is_not_asked_again(): void
    {
        $this->onSunday();
        $user = $this->athlete();
        $user->runnerProfile->update(['week_check_week' => WeekAvailabilityController::upcomingWeekKey()]);

        $this->mock(WebPushService::class)->shouldNotReceive('sendToUser');

        $this->artisan('push:week-check')->assertSuccessful();
    }

    /** Ohne aktiven Plan führt die Abfrage ins Leere — also keine Erinnerung. */
    public function test_no_reminder_without_an_active_plan(): void
    {
        $this->onSunday();
        $this->athlete(withPlan: false);

        $this->mock(WebPushService::class)->shouldNotReceive('sendToUser');

        $this->artisan('push:week-check')->assertSuccessful();
    }

    public function test_no_reminder_without_a_subscription(): void
    {
        $this->onSunday();
        $this->athlete(withPush: false);

        $this->mock(WebPushService::class)->shouldNotReceive('sendToUser');

        $this->artisan('push:week-check')->assertSuccessful();
    }

    public function test_no_reminder_when_push_is_switched_off(): void
    {
        $this->onSunday();
        $this->athlete()->forceFill(['push_notifications_enabled' => false])->save();

        $this->mock(WebPushService::class)->shouldNotReceive('sendToUser');

        $this->artisan('push:week-check')->assertSuccessful();
    }
}
