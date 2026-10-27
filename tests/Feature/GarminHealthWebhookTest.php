<?php

namespace Tests\Feature;

use App\Models\GarminConnection;
use App\Models\GarminDailyMetric;
use App\Models\GarminWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Erholungswerte per Push ueber die offizielle Health API.
 */
class GarminHealthWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function connected(array $permissions = ['HEALTH_EXPORT']): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        GarminConnection::create([
            'user_id'                 => $user->id,
            'garmin_user_id'          => 'g-' . $user->id,
            'access_token'            => 'a',
            'refresh_token'           => 'r',
            'access_token_expires_at' => now()->addHours(20),
            'permissions'             => $permissions,
            'ai_consent_at'           => now(),
        ]);

        return $user;
    }

    private function metric(User $user, string $date): ?GarminDailyMetric
    {
        return GarminDailyMetric::where('user_id', $user->id)->whereDate('date', $date)->first();
    }

    public function test_the_summaries_of_one_day_land_in_one_row(): void
    {
        $user = $this->connected();
        $id   = 'g-' . $user->id;

        $this->postJson('/garmin/webhook/health', ['dailies' => [[
            'userId' => $id, 'calendarDate' => '2026-10-27',
            'restingHeartRateInBeatsPerMinute' => 48, 'averageStressLevel' => 27, 'steps' => 9123,
        ]]])->assertOk();

        $this->postJson('/garmin/webhook/health', ['sleeps' => [[
            'userId' => $id, 'calendarDate' => '2026-10-27', 'durationInSeconds' => 30000,
            'deepSleepDurationInSeconds' => 5400, 'lightSleepDurationInSeconds' => 14400, 'remSleepInSeconds' => 7200,
            'overallSleepScore' => ['value' => 82, 'qualifierKey' => 'GOOD'],
        ]]])->assertOk();

        $this->postJson('/garmin/webhook/health', ['hrv' => [[
            'userId' => $id, 'calendarDate' => '2026-10-27', 'lastNightAvg' => 61, 'lastNight5MinHigh' => 90,
        ]]])->assertOk();

        $this->postJson('/garmin/webhook/health', ['stressDetails' => [[
            'userId' => $id, 'calendarDate' => '2026-10-27',
            'timeOffsetBodyBatteryValues' => ['0' => 34, '180' => 88, '360' => 71],
        ]]])->assertOk();

        $m = $this->metric($user, '2026-10-27');
        $this->assertSame(48, $m->resting_hr);
        $this->assertSame(27, $m->stress_avg);
        $this->assertSame(9123, $m->steps);
        $this->assertSame(7.5, $m->sleep_hours);   // ohne Wachzeit
        $this->assertSame(82, $m->sleep_score);
        $this->assertSame(61.0, $m->hrv);
        $this->assertSame(34, $m->body_battery_low);
        $this->assertSame(88, $m->body_battery_high);
        $this->assertSame(1, GarminDailyMetric::count());
    }

    public function test_a_later_summary_does_not_erase_other_columns(): void
    {
        $user = $this->connected();
        GarminDailyMetric::create(['user_id' => $user->id, 'date' => '2026-10-27', 'hrv' => 55, 'resting_hr' => 50]);

        $this->postJson('/garmin/webhook/health', ['dailies' => [[
            'userId' => 'g-' . $user->id, 'calendarDate' => '2026-10-27', 'restingHeartRateInBeatsPerMinute' => 47,
        ]]]);

        $m = $this->metric($user, '2026-10-27');
        $this->assertSame(47, $m->resting_hr);
        $this->assertSame(55.0, $m->hrv);
    }

    public function test_missing_or_negative_values_stay_empty(): void
    {
        // -1 heisst bei Garmin "zu wenig Daten", nicht "kein Stress".
        $user = $this->connected();

        $this->postJson('/garmin/webhook/health', ['dailies' => [[
            'userId' => 'g-' . $user->id, 'calendarDate' => '2026-10-27',
            'averageStressLevel' => -1, 'restingHeartRateInBeatsPerMinute' => 0, 'steps' => 1200,
        ]]]);

        $m = $this->metric($user, '2026-10-27');
        $this->assertNull($m->stress_avg);
        $this->assertNull($m->resting_hr);
        $this->assertSame(1200, $m->steps);
    }

    public function test_body_battery_parts_widen_the_range_of_the_day(): void
    {
        $user = $this->connected();
        $id   = 'g-' . $user->id;

        $this->postJson('/garmin/webhook/health', ['stressDetails' => [[
            'userId' => $id, 'calendarDate' => '2026-10-27', 'timeOffsetBodyBatteryValues' => ['0' => 40, '180' => 90],
        ]]]);
        $this->postJson('/garmin/webhook/health', ['stressDetails' => [[
            'userId' => $id, 'calendarDate' => '2026-10-27', 'timeOffsetBodyBatteryValues' => ['43200' => 60, '43380' => 25],
        ]]]);

        $m = $this->metric($user, '2026-10-27');
        $this->assertSame(25, $m->body_battery_low);
        $this->assertSame(90, $m->body_battery_high);
    }

    public function test_an_unknown_user_is_recorded_and_nothing_stored(): void
    {
        $this->postJson('/garmin/webhook/health', ['dailies' => [[
            'userId' => 'nobody', 'calendarDate' => '2026-10-27', 'steps' => 5,
        ]]])->assertOk();

        $this->assertSame(0, GarminDailyMetric::count());
        $this->assertSame(GarminWebhookEvent::OUTCOME_UNKNOWN_USER, GarminWebhookEvent::first()->outcome);
    }

    public function test_without_the_health_permission_nothing_is_stored(): void
    {
        $user = $this->connected(['ACTIVITY_EXPORT']);

        $this->postJson('/garmin/webhook/health', ['hrv' => [[
            'userId' => 'g-' . $user->id, 'calendarDate' => '2026-10-27', 'lastNightAvg' => 60,
        ]]]);

        $this->assertSame(0, GarminDailyMetric::count());
        $this->assertSame(GarminWebhookEvent::OUTCOME_NOT_PERMITTED, GarminWebhookEvent::first()->outcome);
    }

    public function test_every_push_keeps_its_raw_payload_for_fourteen_days(): void
    {
        $user = $this->connected();

        $this->postJson('/garmin/webhook/health', ['userMetrics' => [[
            'userId' => 'g-' . $user->id, 'calendarDate' => '2026-10-27', 'vo2Max' => 54,
        ]]]);

        $event = GarminWebhookEvent::first();
        $this->assertSame(54, json_decode($event->payload, true)['vo2Max']);

        $this->travel(15)->days();
        $this->artisan('garmin:prune-webhook-payloads')->assertSuccessful();

        $this->assertNull($event->fresh()->payload);
        $this->assertNotNull($event->fresh());
    }

    public function test_the_dashboard_and_plan_see_garmin_health_from_the_official_connection(): void
    {
        $this->assertTrue($this->connected()->receivesGarminHealth());
        $this->assertFalse($this->connected(['WORKOUT_IMPORT'])->receivesGarminHealth());
        $this->assertFalse(User::factory()->create()->receivesGarminHealth());
    }
}
