<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\IgnoredActivity;
use App\Models\User;
use App\Services\ActivityImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * FIT-Datei von Hand hochladen (Jan, 08.11.2026) — für Zwift ohne Strava
 * und alles andere, was keine Quelle liefert.
 */
class FitUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow('2026-11-08 16:00:00');

        $this->user = User::factory()->onboarded()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** 40 min auf dem Rad, 20 km, Zwift (cycling / virtual_activity). */
    private function zwiftFit(int $start, int $sport = 2, int $subSport = 58): string
    {
        $fitStart = $start - 631065600;
        $def = fn (int $local, int $global, array $fields) => chr(0x40 | $local) . "\x00\x00" . pack('v', $global) . chr(count($fields))
            . implode('', array_map(fn ($f) => chr($f[0]) . chr($f[1]) . chr($f[2]), $fields));

        $msgs = $def(0, 20, [[253, 4, 0x86], [3, 1, 0x02], [5, 4, 0x86], [7, 2, 0x84]]);
        for ($i = 0; $i <= 40; $i++) {
            $msgs .= "\x00" . pack('V', $fitStart + $i * 60) . chr(130) . pack('V', $i * 50000) . pack('v', 200);
        }

        $msgs .= $def(1, 18, [[2, 4, 0x86], [5, 1, 0x00], [6, 1, 0x00], [7, 4, 0x86], [8, 4, 0x86], [9, 4, 0x86], [16, 1, 0x02], [20, 2, 0x84]]);
        $msgs .= "\x01" . pack('V', $fitStart) . chr($sport) . chr($subSport) . pack('V', 2400000) . pack('V', 2400000) . pack('V', 2000000) . chr(130) . pack('v', 205);

        return chr(12) . chr(0x20) . pack('v', 2100) . pack('V', strlen($msgs)) . '.FIT' . $msgs . "\x00\x00";
    }

    private function upload(string $bytes, string $name = 'zwift.fit')
    {
        return $this->actingAs($this->user)->post('/activities/upload', [
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        ]);
    }

    public function test_a_zwift_file_becomes_a_virtual_ride(): void
    {
        $start = now()->subHour()->timestamp;

        $response = $this->upload($this->zwiftFit($start));

        $activity = Activity::sole();
        $response->assertRedirect(route('activities.show', $activity));
        $this->assertSame('VirtualRide', $activity->type);
        $this->assertSame(Activity::SOURCE_FILE, $activity->source);
        $this->assertSame($start, (int) $activity->file_start);
        $this->assertEqualsWithDelta(20000, $activity->distance, 1);
        $this->assertSame(2400, $activity->moving_time);
        $this->assertEquals(205, $activity->average_watts);
        $this->assertSame('Virtuelle Radfahrt', $activity->name);
    }

    public function test_the_same_file_twice_is_one_activity(): void
    {
        $bytes = $this->zwiftFit(now()->subHour()->timestamp);

        $this->upload($bytes);
        $this->upload($bytes);

        $this->assertSame(1, Activity::count());
    }

    public function test_a_ride_already_there_from_strava_is_not_doubled(): void
    {
        $start = now()->subHour()->timestamp;
        app(ActivityImportService::class)->store($this->user->id, Activity::SOURCE_STRAVA, 555, [
            'name' => 'Zwift - Watopia', 'type' => 'VirtualRide', 'distance' => 20050,
            'moving_time' => 2400, 'elapsed_time' => 2400, 'average_speed' => 8.3,
            'start_date' => Carbon::createFromTimestampUTC($start),
        ]);

        $this->upload($this->zwiftFit($start));

        $activity = Activity::sole();
        $this->assertSame('Zwift - Watopia', $activity->name);
        $this->assertSame($start, (int) $activity->file_start);
    }

    public function test_a_deleted_upload_stays_deleted(): void
    {
        $start = now()->subHour()->timestamp;
        IgnoredActivity::create(['user_id' => $this->user->id, 'source' => Activity::SOURCE_FILE, 'external_id' => $start]);

        $this->upload($this->zwiftFit($start))->assertSessionHasErrors('file');

        $this->assertSame(0, Activity::count());
    }

    public function test_garbage_is_refused(): void
    {
        $this->upload('kein fit')->assertSessionHasErrors('file');
        $this->upload('irgendwas', 'bild.png')->assertSessionHasErrors('file');

        $this->assertSame(0, Activity::count());
    }

    public function test_a_run_file_is_a_run(): void
    {
        $this->upload($this->zwiftFit(now()->subHour()->timestamp, sport: 1, subSport: 0));

        $this->assertSame('Run', Activity::sole()->type);
    }
}
