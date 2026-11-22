<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use App\Services\ActivityMatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gemeldet am 22.11.2026: Lauf um 9:44 gestartet, die App zeigte 8:44.
 * start_date stand in UTC und wurde als Berliner Zeit gelesen.
 */
class ActivityStartTimeTest extends TestCase
{
    use RefreshDatabase;

    private function make(mixed $start, array $extra = []): Activity
    {
        $user = User::factory()->onboarded()->create();

        return Activity::create(array_merge([
            'user_id'     => $user->id,
            'source'      => Activity::SOURCE_GARMIN,
            'name'        => 'Lauf',
            'type'        => 'Run',
            'distance'    => 10000,
            'moving_time' => 3000,
            'elapsed_time' => 3050,
            'average_speed' => 10000 / 3000,
            'start_date'  => $start,
        ], $extra));
    }

    private function raw(Activity $a): string
    {
        return (string) DB::table('activities')->where('id', $a->id)->value('start_date');
    }

    public function test_a_utc_timestamp_is_stored_and_shown_in_berlin_time(): void
    {
        // 22.11.2026 08:44:33 UTC = 09:44:33 in Berlin (Winterzeit)
        $a = $this->make(Carbon::createFromTimestampUTC(1795337073));

        $this->assertStringStartsWith('2026-11-22 09:44:33', $this->raw($a));
        $this->assertSame('09:44', $a->fresh()->start_date->format('H:i'));
    }

    public function test_stravas_iso_string_with_z_is_converted(): void
    {
        $a = $this->make('2026-07-01T05:30:00Z');

        // Sommerzeit: +2 h
        $this->assertStringStartsWith('2026-07-01 07:30:00', $this->raw($a));
    }

    public function test_a_run_after_midnight_counts_for_the_berlin_day(): void
    {
        // 23:30 UTC am 21.11. = 00:30 am 22.11. in Berlin
        $a = $this->make('2026-11-21T23:30:00Z');

        $this->assertSame(1, Activity::whereDate('start_date', '2026-11-22')->whereKey($a->id)->count());
    }

    public function test_a_text_without_zone_counts_as_berlin_time(): void
    {
        $a = $this->make('2026-11-22 09:44:00');

        $this->assertStringStartsWith('2026-11-22 09:44:00', $this->raw($a));
    }

    public function test_the_same_run_from_another_source_is_still_found(): void
    {
        $a = $this->make(Carbon::createFromTimestampUTC(1795337073));

        $twin = app(ActivityMatcher::class)->findTwin($a->user_id, Activity::SOURCE_STRAVA, [
            'start_date'  => '2026-11-22T08:45:00Z',
            'type'        => 'Run',
            'distance'    => 10050,
            'moving_time' => 3010,
        ]);

        $this->assertSame($a->id, $twin?->id);
    }
}
