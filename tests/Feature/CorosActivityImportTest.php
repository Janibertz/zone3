<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\CorosConnection;
use App\Models\User;
use App\Services\Coros\CorosActivityImporter;
use App\Services\Coros\CorosActivityParser;
use App\Services\Coros\CorosDisconnector;
use App\Services\Fit\FitDecoder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * COROS als dritte Aktivitaetsquelle. Die Texte haben den Aufbau der echten
 * Antworten (`coros:probe`, 06.11.2026), Zahlen und Orte sind erfunden; die
 * FIT-Datei baut der Test selbst.
 */
class CorosActivityImportTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-06 12:00:00';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        Queue::fake();
        config(['services.coros.enabled' => true, 'services.coros.base_url' => 'https://mcpeu.coros.com']);

        $this->user = User::factory()->onboarded()->create(['activity_source' => 'coros']);
        CorosConnection::create([
            'user_id' => $this->user->id, 'client_id' => 'c', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'access_token_expires_at' => now()->addDays(20), 'ai_consent_at' => now(),
            'activities_synced_at' => now()->subHour(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Start vor einer Stunde: frisch genug fuer Push und Zuordnung. */
    private function start(): int
    {
        return Carbon::parse(self::NOW)->subHour()->timestamp;
    }

    private function list(): string
    {
        $start = $this->start();
        $end   = $start + 1800 + 60;

        return <<<TXT
            Sport Records — 2026-11-04 to 2026-11-06 (2 records)
            ========================

            1. Outdoor Run — 2026-11-06
               Location: Musterstadt Laufen
               Start Coordinates: 50.100000, 8.600000
               Time Window: startTimestamp={$start} | endTimestamp={$end}
               Duration: 30:00 | Distance: 6.00 km
               Average Pace: 5:00 /km | Avg HR: 150 bpm | Calories: 400 kcal
               LabelId: 400000000000000001 | SportType: 100

            2. Indoor Bike — 2026-11-04
               Time Window: startTimestamp=1793700000 | endTimestamp=1793703600
               Duration: 1:00:00 | Distance: 30.00 km
               Average Speed: 30.0 km/h | Avg HR: 130 bpm | Calories: 500 kcal
               LabelId: 400000000000000002 | SportType: 201
            TXT;
    }

    /** Eine kleine FIT-Datei: 6 km in 30 min, zwei Runden, Punkte alle 60 s. */
    public static function fit(int $start): string
    {
        $fitStart = $start - 631065600;
        $msgs = '';

        // Definitionen: record (local 0), lap (local 1), session (local 2)
        $def = fn (int $local, int $global, array $fields) => chr(0x40 | $local) . "\x00\x00" . pack('v', $global) . chr(count($fields))
            . implode('', array_map(fn ($f) => chr($f[0]) . chr($f[1]) . chr($f[2]), $fields));

        $msgs .= $def(0, 20, [[253, 4, 0x86], [0, 4, 0x85], [1, 4, 0x85], [3, 1, 0x02], [5, 4, 0x86]]);
        for ($i = 0; $i <= 30; $i++) {
            $lat = (int) round((50.1 + $i * 0.001) * 2147483648 / 180);
            $lng = (int) round(8.6 * 2147483648 / 180);
            $msgs .= "\x00" . pack('V', $fitStart + $i * 60) . pack('V', $lat & 0xFFFFFFFF) . pack('V', $lng & 0xFFFFFFFF) . chr(150) . pack('V', $i * 20000);
        }

        $msgs .= $def(1, 19, [[2, 4, 0x86], [7, 4, 0x86], [8, 4, 0x86], [9, 4, 0x86], [15, 1, 0x02]]);
        $msgs .= "\x01" . pack('V', $fitStart) . pack('V', 900000) . pack('V', 900000) . pack('V', 300000) . chr(148);
        $msgs .= "\x01" . pack('V', $fitStart + 900) . pack('V', 900000) . pack('V', 900000) . pack('V', 300000) . chr(152);

        $msgs .= $def(2, 18, [[2, 4, 0x86], [7, 4, 0x86], [8, 4, 0x86], [9, 4, 0x86], [16, 1, 0x02], [22, 2, 0x84]]);
        $msgs .= "\x02" . pack('V', $fitStart) . pack('V', 1860000) . pack('V', 1800000) . pack('V', 600000) . chr(150) . pack('v', 42);

        return chr(12) . chr(0x20) . pack('v', 2100) . pack('V', strlen($msgs)) . '.FIT' . $msgs . "\x00\x00";
    }

    private function fakeCoros(array &$tools = []): void
    {
        $list = $this->list();
        $fit  = self::fit($this->start());

        Http::fake([
            'mcpeu.coros.com/mcp' => function (Request $request) use (&$tools, $list) {
                $tool    = $request['params']['name'];
                $args    = (array) ($request['params']['arguments'] ?? []);
                $tools[] = $tool . (isset($args['labelId']) ? ':' . $args['labelId'] : '');
                $text    = match ($tool) {
                    'querySportRecords'                => $list,
                    'queryActivityFitFileDownloadUrls' => "Activity FIT file download URL(s):\n1. x.fit\n   https://s3eu.coros.com/fit/v2/1/{$args['labelId']}.fit",
                    'queryDevices'                     => "Bound Devices (1)\n========================\n\n1. COROS PACE 9\n   Model Name: X",
                    default                            => '',
                };

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => $text]]]]);
            },
            's3eu.coros.com/*' => Http::response($fit),
        ]);
    }

    public function test_the_fit_decoder_reads_points_laps_and_session(): void
    {
        $d = (new FitDecoder())->decode(self::fit($this->start()));

        $this->assertCount(31, $d['records']);
        $this->assertSame($this->start(), $d['records'][0]['timestamp']);
        $this->assertEqualsWithDelta(50.1, $d['records'][0]['lat'], 0.00001);
        $this->assertSame(6000.0, (float) $d['records'][30]['distance']);
        $this->assertCount(2, $d['laps']);
        $this->assertSame(1800.0, (float) $d['session']['total_timer_time']);
        $this->assertSame(42, $d['session']['total_ascent']);
    }

    public function test_the_parser_reads_the_list(): void
    {
        $records = (new CorosActivityParser())->records($this->list());

        $this->assertCount(2, $records);
        $this->assertSame(400000000000000001, $records[0]['label_id']);
        $this->assertSame(100, $records[0]['sport_type']);
        $this->assertSame('Musterstadt Laufen', $records[0]['name']);
        $this->assertSame(1800, $records[0]['duration']);
        $this->assertSame(6000.0, $records[0]['distance']);
        $this->assertNull($records[1]['name']);
        $this->assertSame(3600, $records[1]['duration']);
    }

    public function test_a_new_run_arrives_with_line_laps_and_device(): void
    {
        $tools = [];
        $this->fakeCoros($tools);

        $result = app(CorosActivityImporter::class)->sync($this->user);

        $this->assertSame(2, $result['imported']);
        $this->assertNotContains('queryMenstruationCycles', $tools);

        $run = Activity::where('coros_label_id', 400000000000000001)->first();
        $this->assertSame('coros', $run->source);
        $this->assertSame('Run', $run->type);
        $this->assertSame('Musterstadt Laufen', $run->name);
        $this->assertSame(1800, $run->moving_time);
        $this->assertSame(1860, $run->elapsed_time);
        $this->assertEquals(6000, $run->distance);
        $this->assertEquals(42, $run->total_elevation_gain);
        $this->assertCount(2, $run->laps);
        $this->assertNotEmpty($run->polyline['polyline']);
        $this->assertSame('COROS PACE 9', $run->device_name);
        $this->assertSame(Carbon::createFromTimestampUTC($this->start())->toDateTimeString(), $run->start_date->toDateTimeString());

        $this->assertSame('VirtualRide', Activity::where('coros_label_id', 400000000000000002)->value('type'));

        // Ein zweiter Abruf holt nichts doppelt.
        $tools = [];
        $again = app(CorosActivityImporter::class)->sync($this->user->fresh());
        $this->assertSame(0, $again['imported']);
        $this->assertSame(2, Activity::where('user_id', $this->user->id)->count());
        $this->assertEmpty(array_filter($tools, fn ($t) => str_starts_with($t, 'queryActivityFitFileDownloadUrls')));
    }

    public function test_nothing_is_read_without_consent_or_when_another_source_is_chosen(): void
    {
        Http::fake();

        $this->user->corosConnection->update(['ai_consent_at' => null]);
        $this->assertNull(app(CorosActivityImporter::class)->sync($this->user->fresh()));

        $this->user->corosConnection->update(['ai_consent_at' => now()]);
        \App\Models\StravaAccount::create(['user_id' => $this->user->id, 'strava_id' => 1, 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
        $this->user->update(['activity_source' => 'strava']);
        $this->assertNull(app(CorosActivityImporter::class)->sync($this->user->fresh()));

        Http::assertNothingSent();
    }

    public function test_disconnecting_deletes_what_coros_created(): void
    {
        $this->fakeCoros();
        app(CorosActivityImporter::class)->sync($this->user);

        // Von Strava angelegt, COROS nur angehaengt: die Aktivitaet bleibt.
        $strava = Activity::create(['user_id' => $this->user->id, 'strava_id' => 99, 'coros_label_id' => 400000000000000099, 'source' => 'strava', 'name' => 'S', 'type' => 'Run', 'distance' => 5000, 'moving_time' => 1500, 'elapsed_time' => 1500, 'start_date' => now()->subDays(3)]);

        app(CorosDisconnector::class)->disconnect($this->user->fresh());

        $this->assertSame([$strava->id], Activity::where('user_id', $this->user->id)->pluck('id')->all());
        $this->assertNull($strava->fresh()->coros_label_id);
    }

    /**
     * Gemeldet am 22.11.2026: COROS listet ein laufendes Training schon mit
     * dem Zwischenstand, die FIT-Datei kommt erst danach. Gespeichert wurde
     * der Zwischenstand und nie wieder angefasst.
     */
    private function fakeLiveRun(string &$state, array &$tools = []): void
    {
        $start = $this->start();
        $fit   = self::fit($start);

        Http::fake([
            'mcpeu.coros.com/mcp' => function (Request $request) use (&$state, &$tools, $start) {
                $tool    = $request['params']['name'];
                $args    = (array) ($request['params']['arguments'] ?? []);
                $tools[] = $tool;
                [$dur, $km] = $state === 'running' ? ['5:00', '1.00'] : ['30:00', '6.00'];
                $end = $start + 1860;

                if ($tool === 'queryActivityFitFileDownloadUrls' && $state === 'running') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'This activity is still in progress; the FIT file is available after it ends.']]]]);
                }

                $text = match ($tool) {
                    'querySportRecords' => "Sport Records\n\n1. Outdoor Run — 2026-11-06\n   Time Window: startTimestamp={$start} | endTimestamp={$end}\n   Duration: {$dur} | Distance: {$km} km\n   Avg HR: 150 bpm\n   LabelId: 400000000000000009 | SportType: 100\n",
                    'queryActivityFitFileDownloadUrls' => "1. x.fit\n   https://s3eu.coros.com/fit/v2/1/{$args['labelId']}.fit",
                    'queryDevices' => "1. COROS PACE 3",
                    default => '',
                };

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => $text]]]]);
            },
            's3eu.coros.com/*' => Http::response($fit),
        ]);
    }

    public function test_a_run_still_in_progress_waits_for_the_next_sync(): void
    {
        $state = 'running';
        $this->fakeLiveRun($state);

        $result = app(CorosActivityImporter::class)->sync($this->user);

        $this->assertSame(1, $result['in_progress']);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, Activity::where('user_id', $this->user->id)->count());

        $state = 'done';
        $again = app(CorosActivityImporter::class)->sync($this->user->fresh());

        $this->assertSame(1, $again['imported']);
        $run = Activity::where('coros_label_id', 400000000000000009)->first();
        $this->assertEquals(6000, $run->distance);
        $this->assertSame(1800, $run->moving_time);
        $this->assertCount(2, $run->laps);
    }

    public function test_a_stored_partial_run_is_refreshed_with_its_session_and_review(): void
    {
        // So stand es vor dem Fix in der Datenbank: Zwischenstand, ohne FIT,
        // einer Einheit zugeordnet und schon besprochen.
        $activity = Activity::create([
            'user_id' => $this->user->id, 'source' => 'coros', 'coros_label_id' => 400000000000000009,
            'name' => 'Aktivität', 'type' => 'Run', 'distance' => 1000, 'moving_time' => 300, 'elapsed_time' => 300,
            'average_speed' => 3.333, 'start_date' => Carbon::createFromTimestampUTC($this->start()),
        ]);
        $session = \App\Models\TrainingSession::create([
            'user_id' => $this->user->id, 'planned_date' => '2026-11-06', 'type' => 'long_run', 'title' => 'Langer Lauf',
            'status' => 'completed', 'intensity' => 'low', 'activity_id' => $activity->id, 'distance_km' => 1.0, 'duration_min' => 5,
            'coach_review' => 'Heute bewusst kurz.', 'reviewed_at' => now(),
        ]);

        $state = 'done';
        $tools = [];
        $this->fakeLiveRun($state, $tools);

        $result = app(CorosActivityImporter::class)->sync($this->user);

        $this->assertSame(1, $result['refreshed']);
        $activity->refresh();
        $this->assertEquals(6000, $activity->distance);
        $this->assertSame(1800, $activity->moving_time);
        $this->assertCount(2, $activity->laps);

        $session->refresh();
        $this->assertEquals(6.0, $session->distance_km);
        $this->assertSame(30, $session->duration_min);
        $this->assertNull($session->reviewed_at);
        $this->assertNull($session->coach_review);
        Queue::assertPushed(\App\Jobs\GenerateSessionReviewJob::class, fn ($job) => $job->sessionId === $session->id);

        // Steht es einmal richtig da, frischt kein Abruf mehr auf.
        $tools = [];
        $again = app(CorosActivityImporter::class)->sync($this->user->fresh());
        $this->assertSame(0, $again['refreshed']);
        $this->assertNotContains('queryActivityFitFileDownloadUrls', $tools);
    }
}
