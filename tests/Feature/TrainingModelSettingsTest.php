<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use App\Services\TrainingModelService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trainingsmodell, zweites Zeitfenster und Rad / Zwift — die Einstellungen,
 * die Onboarding und Profil teilen.
 */
class TrainingModelSettingsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00'); // ein Mittwoch
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function week(array $tuesday): array
    {
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $d) {
            $days[$d] = ['available' => true, 'duration_min' => 60];
        }
        $days['tuesday'] = $tuesday;

        return ['availability' => $days];
    }

    public function test_the_training_model_is_stored(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson(route('onboarding.training-model'), [
            'training_model'           => 'norwegian',
            'running_experience_years' => 6,
            'has_lactate_meter'        => true,
        ])->assertOk();

        $profile = $user->refresh()->runnerProfile;
        $this->assertSame('norwegian', $profile->training_model);
        $this->assertSame(6, $profile->running_experience_years);
        $this->assertTrue($profile->has_lactate_meter);
    }

    public function test_an_unknown_training_model_is_rejected(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson(route('onboarding.training-model'), [
            'training_model'    => 'pyramidal',
            'has_lactate_meter' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('training_model');
    }

    public function test_a_second_window_is_stored_on_an_available_day(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson(route('onboarding.availability'), $this->week([
            'available' => true, 'duration_min' => 60, 'second_min' => 45,
        ]))->assertOk();

        $this->assertSame(45, $user->refresh()->runnerProfile->weekly_availability['tuesday']['second_min']);
    }

    /** Ein gesperrter Tag hat kein Abendfenster, auch wenn das Formular eins mitschickt. */
    public function test_a_second_window_on_an_unavailable_day_is_dropped(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson(route('onboarding.availability'), $this->week([
            'available' => false, 'duration_min' => 0, 'second_min' => 45,
        ]))->assertOk();

        $this->assertArrayNotHasKey('second_min', $user->refresh()->runnerProfile->weekly_availability['tuesday']);
    }

    public function test_an_empty_second_window_is_not_stored(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson(route('onboarding.availability'), $this->week([
            'available' => true, 'duration_min' => 60, 'second_min' => null,
        ]))->assertOk();

        $this->assertArrayNotHasKey('second_min', $user->refresh()->runnerProfile->weekly_availability['tuesday']);
    }

    public function test_the_bike_settings_are_stored(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson(route('onboarding.bike'), [
            'bike_enabled'        => true,
            'bike_season'         => 'all_year',
            'bike_purpose'        => 'intervals',
            'bike_max_per_week'   => 3,
            'bike_preferred_days' => ['tuesday', 'thursday', 'tuesday'],
            'ftp'                 => 245,
        ])->assertOk();

        $profile = $user->refresh()->runnerProfile;
        $this->assertTrue($profile->bike_enabled);
        $this->assertSame('all_year', $profile->bike_season);
        $this->assertSame('intervals', $profile->bike_purpose);
        $this->assertSame(3, $profile->bike_max_per_week);
        $this->assertSame(['tuesday', 'thursday'], $profile->bike_preferred_days);
        $this->assertSame(245, (int) $profile->ftp);
    }

    /** Die FTP ist optional — lockere Radeinheiten gehen auch ohne. */
    public function test_bike_settings_without_ftp_are_accepted(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson(route('onboarding.bike'), [
            'bike_enabled'      => true,
            'bike_season'       => 'winter',
            'bike_purpose'      => 'easy',
            'bike_max_per_week' => 2,
            'ftp'               => null,
        ])->assertOk();

        $this->assertNull($user->refresh()->runnerProfile->ftp);
    }

    public function test_invalid_bike_settings_are_rejected(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson(route('onboarding.bike'), [
            'bike_enabled'        => true,
            'bike_season'         => 'summer',
            'bike_purpose'        => 'both',
            'bike_max_per_week'   => 7,
            'bike_preferred_days' => ['someday'],
            'ftp'                 => 2000,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'bike_season', 'bike_max_per_week', 'bike_preferred_days.0', 'ftp',
        ]);
    }

    public function test_new_profiles_default_to_polarized_without_bike(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson(route('onboarding.availability'), $this->week([
            'available' => true, 'duration_min' => 60,
        ]))->assertOk();

        $profile = $user->refresh()->runnerProfile;
        $this->assertSame('polarized', $profile->training_model);
        $this->assertFalse($profile->bike_enabled);
    }

    private function weeklyRuns(User $user, float $kmPerWeek): void
    {
        // Drei volle Wochen vor der laufenden, je zwei Läufe.
        foreach ([1, 2, 3] as $weeksAgo) {
            foreach ([0, 2] as $offset) {
                Activity::create([
                    'user_id'      => $user->id,
                    'strava_id'    => $this->seq++,
                    'name'         => 'Lauf',
                    'type'         => 'Run',
                    'distance'     => $kmPerWeek / 2 * 1000,
                    'moving_time'  => (int) ($kmPerWeek / 2 * 330),
                    'elapsed_time' => (int) ($kmPerWeek / 2 * 330),
                    'start_date'   => now()->startOfWeek()->subWeeks($weeksAgo)->addDays($offset)->setTime(7, 0),
                ]);
            }
        }
    }

    public function test_an_experienced_runner_with_volume_is_ready_for_norwegian(): void
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->updateOrCreate([], ['running_experience_years' => 5]);
        $this->weeklyRuns($user, 50);

        $r = app(TrainingModelService::class)->norwegianReadiness($user->refresh()->runnerProfile, $user->id);

        $this->assertTrue($r['recommended']);
        $this->assertSame([], $r['reasons']);
        $this->assertEqualsWithDelta(50.0, $r['weekly_km'], 0.1);
    }

    public function test_little_experience_and_low_volume_both_give_a_reason(): void
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->updateOrCreate([], ['running_experience_years' => 1]);
        $this->weeklyRuns($user, 24);

        $r = app(TrainingModelService::class)->norwegianReadiness($user->refresh()->runnerProfile, $user->id);

        $this->assertFalse($r['recommended']);
        $this->assertCount(2, $r['reasons']);
        $this->assertStringContainsString('1 Jahr.', $r['reasons'][0]);
        $this->assertStringContainsString('24 km', $r['reasons'][1]);
    }

    /** Ohne Strava-Daten urteilt Zone3 nicht über den Umfang — keine Zahl, kein Einwand. */
    public function test_missing_volume_is_not_held_against_the_runner(): void
    {
        $user = User::factory()->onboarded()->create();
        $user->runnerProfile()->updateOrCreate([], ['running_experience_years' => 4]);

        $r = app(TrainingModelService::class)->norwegianReadiness($user->refresh()->runnerProfile, $user->id);

        $this->assertTrue($r['recommended']);
        $this->assertNull($r['weekly_km']);
    }

    public function test_the_profile_page_carries_the_readiness(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('norwegianReadiness.recommended'));
    }
}
