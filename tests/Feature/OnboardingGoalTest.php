<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Der Ziel-Schritt im Onboarding (Usability-Befund 03.11.2026).
 *
 * Wer von „Woher kommen deine Läufe?“ zurückging und „Ziel sichern“ noch
 * einmal drückte, hatte das Rennen doppelt. Und der Name hieß „optional“,
 * war aber Pflicht — die Meldung stand unterhalb des sichtbaren Bereichs.
 */
class OnboardingGoalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function goal(array $overrides = []): array
    {
        return array_merge([
            'name'                => 'HAJ Halbmarathon',
            'race_distance'       => 'half_marathon',
            'target_time_hours'   => 1,
            'target_time_minutes' => 29,
            'race_date'           => '2027-04-11',
        ], $overrides);
    }

    public function test_saving_the_goal_twice_keeps_one_race(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);

        $this->actingAs($user)->postJson(route('onboarding.goal'), $this->goal())->assertOk();
        $this->actingAs($user)->postJson(route('onboarding.goal'), $this->goal(['target_time_minutes' => 25]))->assertOk();

        $this->assertSame(1, Event::where('user_id', $user->id)->count());
        $this->assertSame(25, (int) Event::where('user_id', $user->id)->first()->target_time_minutes,
            'Das zweite Sichern ändert das Rennen, statt ein zweites anzulegen');
    }

    public function test_the_name_is_optional(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);

        $this->actingAs($user)->postJson(route('onboarding.goal'), $this->goal(['name' => null]))->assertOk();

        $this->assertSame('Halbmarathon April 2027', Event::where('user_id', $user->id)->first()->name);
    }

    public function test_a_new_onboarding_run_creates_a_new_race(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);

        $this->actingAs($user)->postJson(route('onboarding.goal'), $this->goal())->assertOk();
        $this->actingAs($user)->post(route('onboarding.complete'));
        $this->actingAs($user)->postJson(route('onboarding.goal'), $this->goal(['name' => 'Berlin', 'race_distance' => 'marathon']))->assertOk();

        $this->assertSame(2, Event::where('user_id', $user->id)->count(),
            'Nach dem Abschluss ist ein neues Ziel ein neues Rennen');
    }

    public function test_validation_messages_are_german(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);

        $this->actingAs($user)->postJson(route('onboarding.goal'), $this->goal(['race_date' => null]))
            ->assertStatus(422)
            ->assertJsonPath('errors.race_date.0', 'Datum fehlt.');
    }
}
