<?php

namespace Tests\Feature;

use App\Jobs\SyncGarminHealthJob;
use App\Models\GarminDailyMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Der Check-in ist das verlässlichste Signal dafür, dass der Nutzer wach ist
 * und die Uhr ihre Nachtwerte inzwischen zu Garmin übertragen hat. Deshalb
 * löst er einen Abruf aus — aber nur, wenn er wirklich nötig ist.
 */
class WellbeingGarminSyncTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'energy_level'    => 7,
        'mood'            => 6,
        'sleep_quality'   => 8,
        'muscle_soreness' => 3,
        'stress_level'    => 4,
    ];

    private function connectedUser(): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $user->forceFill(['garmin_session' => 'token', 'garmin_email' => 'a@b.c'])->save();

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_checkin_triggers_a_sync_when_todays_metrics_are_missing(): void
    {
        Queue::fake();

        $this->actingAs($this->connectedUser())
            ->postJson('/api/wellbeing', $this->payload)
            ->assertOk()
            ->assertJsonPath('garmin_queued', true);

        Queue::assertPushed(SyncGarminHealthJob::class);
    }

    /** Sind die Werte schon da, waere ein Abruf reine Last. */
    public function test_no_sync_when_todays_metrics_already_exist(): void
    {
        Queue::fake();
        $user = $this->connectedUser();

        GarminDailyMetric::create([
            'user_id' => $user->id,
            'date'    => now()->toDateString(),
            'hrv'     => 45,
        ]);

        $this->actingAs($user)
            ->postJson('/api/wellbeing', $this->payload)
            ->assertOk()
            ->assertJsonPath('garmin_queued', false);

        Queue::assertNotPushed(SyncGarminHealthJob::class);
    }

    /** Ohne Garmin-Verbindung gibt es nichts abzurufen. */
    public function test_no_sync_without_a_garmin_connection(): void
    {
        Queue::fake();

        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $this->actingAs($user)
            ->postJson('/api/wellbeing', $this->payload)
            ->assertOk()
            ->assertJsonPath('garmin_queued', false);

        Queue::assertNotPushed(SyncGarminHealthJob::class);
    }

    /**
     * Der Check-in lässt sich beliebig oft speichern. Ohne Drosselung würde
     * jedes Verschieben eines Reglers einen Abruf bis zu Garmin auslösen.
     */
    public function test_repeated_checkins_are_throttled(): void
    {
        Queue::fake();
        $user = $this->connectedUser();

        $this->actingAs($user)->postJson('/api/wellbeing', $this->payload)->assertOk();
        $this->actingAs($user)->postJson('/api/wellbeing', $this->payload)->assertOk();
        $this->actingAs($user)->postJson('/api/wellbeing', $this->payload)
            ->assertJsonPath('garmin_queued', false);

        Queue::assertPushed(SyncGarminHealthJob::class, 1);
    }
}
