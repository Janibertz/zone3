<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\IgnoredActivity;
use App\Jobs\CalculateThresholdPaceJob;
use App\Models\RunnerProfile;
use App\Models\TrainingSession;
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

    // ── Verlauf im Onboarding (13.11.2026) ──────────────────────────────

    private function history(string $bytes, string $name = 'lauf.fit')
    {
        return $this->actingAs($this->user)->postJson('/activities/upload/history', [
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        ]);
    }

    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fitzip');
        $zip  = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function test_history_works_before_onboarding_is_done(): void
    {
        $this->user = User::factory()->create(['onboarding_completed_at' => null]);

        $this->history($this->zwiftFit(now()->subDays(10)->timestamp, sport: 1, subSport: 0))
            ->assertOk()
            ->assertJson(['imported' => 1, 'known' => 0, 'unreadable' => 0]);

        $this->assertSame('Run', Activity::sole()->type);
    }

    public function test_a_garmin_zip_brings_every_fit_inside(): void
    {
        $bytes = $this->zip([
            'lauf-1.fit'          => $this->zwiftFit(now()->subDays(3)->timestamp, sport: 1, subSport: 0),
            'ordner/LAUF-2.FIT'   => $this->zwiftFit(now()->subDays(5)->timestamp, sport: 1, subSport: 0),
            'liesmich.txt'        => 'nichts',
            '__MACOSX/lauf-1.fit' => 'resource fork',
        ]);

        $this->history($bytes, 'export.zip')->assertOk()->assertJson(['imported' => 2, 'unreadable' => 0]);

        $this->assertSame(2, Activity::count());
    }

    public function test_history_is_stored_quietly(): void
    {
        $start = now()->subDays(2)->setTime(7, 0)->timestamp;
        $session = TrainingSession::create([
            'user_id' => $this->user->id, 'planned_date' => now()->subDays(2)->toDateString(),
            'type' => 'easy_run', 'title' => 'Locker', 'intensity' => 'low', 'status' => 'planned',
        ]);

        $this->history($this->zwiftFit($start, sport: 1, subSport: 0))->assertOk();

        // Kein Abgleich mit dem Plan, kein Review, keine Schwelle nach jeder Datei.
        $this->assertSame('planned', $session->fresh()->status);
        Queue::assertNotPushed(CalculateThresholdPaceJob::class);
        $this->assertNull(RunnerProfile::where('user_id', $this->user->id)->value('pending_pr_activity_id'));
    }

    public function test_done_starts_the_threshold_once(): void
    {
        $this->history($this->zwiftFit(now()->subDays(4)->timestamp, sport: 1, subSport: 0));

        $this->actingAs($this->user)->postJson('/activities/upload/history/done')->assertOk();

        Queue::assertPushed(CalculateThresholdPaceJob::class, 1);
    }

    public function test_done_without_runs_starts_nothing(): void
    {
        $this->history($this->zwiftFit(now()->subDays(4)->timestamp));

        $this->actingAs($this->user)->postJson('/activities/upload/history/done')->assertOk();

        Queue::assertNotPushed(CalculateThresholdPaceJob::class);
    }

    public function test_history_counts_the_known_and_the_broken(): void
    {
        $bytes = $this->zwiftFit(now()->subDays(6)->timestamp, sport: 1, subSport: 0);
        $this->history($bytes);

        $this->history($bytes)->assertJson(['imported' => 0, 'known' => 1]);
        $this->history('kein fit')->assertJson(['unreadable' => 1]);
        $this->history('kaputt', 'export.zip')->assertJson(['unreadable' => 1]);
        $this->history('bild', 'bild.png')->assertStatus(422);
    }
}
