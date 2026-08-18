<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Das Speichern der Wochenverfuegbarkeit — mit und ohne feste Termine.
 *
 * Der Endpunkt lehnte eine leere Bezeichnung mit 422 ab. Weil das Formular
 * den festen Termin mit leerem Feld anlegt, scheiterte damit das Speichern
 * der GESAMTEN Verfuegbarkeit, sobald jemand den Knopf drueckte und noch
 * nichts getippt hatte — und die Oberflaeche zeigte den Fehler nicht an.
 */
class AvailabilitySaveTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $tuesday): array
    {
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $d) {
            $days[$d] = ['available' => true, 'duration_min' => 60];
        }
        $days['tuesday'] = $tuesday;

        return ['availability' => $days];
    }

    private function save(User $user, array $tuesday)
    {
        return $this->actingAs($user)->postJson(route('onboarding.availability'), $this->payload($tuesday));
    }

    private function tuesday(User $user): array
    {
        return $user->refresh()->runnerProfile->weekly_availability['tuesday'];
    }

    public function test_a_fixed_appointment_is_stored(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->save($user, [
            'available' => true, 'duration_min' => 90,
            'fixed' => ['type' => 'interval', 'label' => 'Laufclub'],
        ])->assertOk();

        $stored = $this->tuesday($user);
        $this->assertTrue($stored['available']);
        $this->assertSame('Laufclub', $stored['fixed']['label']);
        $this->assertSame('interval', $stored['fixed']['type']);
    }

    /** Genau der Fall, der das Speichern gekippt hat. */
    public function test_an_empty_label_does_not_reject_the_whole_week(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->save($user, [
            'available' => true, 'duration_min' => 90,
            'fixed' => ['type' => 'interval', 'label' => ''],
        ])->assertOk();

        $stored = $this->tuesday($user);
        $this->assertTrue($stored['available'], 'Der Tag muss verfügbar bleiben');
        $this->assertSame('Fester Termin', $stored['fixed']['label']);
    }

    public function test_saving_works_without_any_fixed_appointment(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->save($user, ['available' => true, 'duration_min' => 90])->assertOk();

        $this->assertArrayNotHasKey('fixed', $this->tuesday($user));
    }

    /** An einem gesperrten Tag wird der Termin verworfen. */
    public function test_a_fixed_appointment_on_a_blocked_day_is_dropped(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->save($user, [
            'available' => false, 'duration_min' => 0,
            'fixed' => ['type' => 'interval', 'label' => 'Laufclub'],
        ])->assertOk();

        $this->assertArrayNotHasKey('fixed', $this->tuesday($user));
    }

    /** Ein erfundener Typ wird abgelehnt, nicht stillschweigend gespeichert. */
    public function test_an_unknown_type_is_rejected(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->save($user, [
            'available' => true, 'duration_min' => 90,
            'fixed' => ['type' => 'yoga', 'label' => 'Laufclub'],
        ])->assertStatus(422);
    }
}
