<?php

namespace Tests\Feature;

use App\Models\CorosConnection;
use App\Models\GarminDailyMetric;
use App\Models\User;
use App\Services\Coros\CorosHealthImporter;
use App\Services\Coros\CorosHealthParser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Erholungswerte von COROS (MCP). Die Texte haben den Aufbau der echten
 * Antworten vom 06.11.2026 (`coros:probe`), die Zahlen sind erfunden.
 */
class CorosHealthImportTest extends TestCase
{
    use RefreshDatabase;

    private const DAILY = <<<'TXT'
        Daily Health Data — Last 3 days | Resting HR: 50 bpm | HRV Baseline: 40 ms
        Note: sleep entries are dated by their wake-up day.

        --- 20261104 ---
        Steps: 0 | Calories: 0 kcal | Exercise: 0 min

        --- 20261105 ---
        Steps: 12,345 | Calories: 600 kcal | Exercise: 50 min
        Stress: Avg 28
        Sleep Summary:
          Total: 7h 10min | Deep: 1h 0min | Light: 4h 0min | REM: 1h 50min | Awake: 20 min

        --- 20261106 ---
        Steps: 2,000 | Calories: 200 kcal | Exercise: 0 min
        Stress: Avg 0
        TXT;

    private const SLEEP = <<<'TXT'
        Sleep Overview
        ========================
        Note: each record below is dated by its wake-up day.

        2026-11-05
        Sleep Score: 80
        Daily Sleep: 7h 20min (incl. naps)
        Main Sleep (asleep): 6h 50min
        Main Sleep Period (incl. awake): 7h 10min
        Main Sleep Window: 2026-11-04 23:00 - 2026-11-05 06:10

        2026-11-06
        Sleep detail for this day is not available yet.
        TXT;

    private const HRV = <<<'TXT'
        Sleep HRV — 2026-11-04 to 2026-11-06
        ========================

        HRV Assessment — Last 3 days
        ========================

        2026-11-06:
          HRV Avg: 88 ms — Above normal
          Normal Range: 50 - 80 ms
          Baseline: 66 ms
        2026-11-05:
          HRV Avg: 64 ms — Normal
          Normal Range: 50 - 80 ms
          Baseline: 65 ms

        Sleep HRV Time Series — Last 3 days
        ========================

        2026-11-05:
          timestamp=1700000000, timezone=4, hrv=72 ms, status=4, confidence=95000
        TXT;

    private const RHR = <<<'TXT'
        Resting Heart Rate — Last 3 days
        ========================

        2026-11-06: 49 bpm
        2026-11-05: 51 bpm
        TXT;

    private const FITNESS = <<<'TXT'
        Fitness Assessment Overview
        ========================

        VO2max: 52
        Running Level: 80
        TXT;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 12:00');
        Queue::fake();
        config(['services.coros.enabled' => true, 'services.coros.base_url' => 'https://mcpeu.coros.com']);

        $this->user = User::factory()->onboarded()->create();
        CorosConnection::create([
            'user_id' => $this->user->id, 'client_id' => 'c', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'access_token_expires_at' => now()->addDays(20), 'ai_consent_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeCoros(array &$tools = []): void
    {
        Http::fake(['mcpeu.coros.com/mcp' => function (Request $request) use (&$tools) {
            $tool    = $request['params']['name'];
            $tools[] = $tool;
            $text    = match ($tool) {
                'queryDailyHealthData'           => self::DAILY,
                'querySleepOverview'             => self::SLEEP,
                'querySleepHrv'                  => self::HRV,
                'queryRestingHeartRate'          => self::RHR,
                'queryFitnessAssessmentOverview' => self::FITNESS,
                default                          => '',
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => json_encode($text)]]]]);
        }]);
    }

    public function test_the_parser_reads_the_coros_texts(): void
    {
        $p = new CorosHealthParser();

        // Ein Tag ohne Messung (0 Schritte, sonst nichts) ist „keine Daten“.
        $this->assertSame(['2026-11-05' => ['steps' => 12345, 'stress_avg' => 28], '2026-11-06' => ['steps' => 2000]], $p->daily(self::DAILY));
        $this->assertSame(['2026-11-05' => ['sleep_score' => 80, 'sleep_hours' => 6.83]], $p->sleep(self::SLEEP));
        // Die Zeitreihe darunter verfaelscht den Tageswert nicht.
        $this->assertSame(['2026-11-06' => ['hrv' => 88.0], '2026-11-05' => ['hrv' => 64.0]], $p->hrv(self::HRV));
        $this->assertSame(['2026-11-06' => ['resting_hr' => 49], '2026-11-05' => ['resting_hr' => 51]], $p->restingHr(self::RHR));
        $this->assertSame(52.0, $p->vo2max(self::FITNESS));
    }

    public function test_it_writes_the_days_and_never_asks_for_cycle_data(): void
    {
        $tools = [];
        $this->fakeCoros($tools);

        app(CorosHealthImporter::class)->sync($this->user);

        $this->assertNotContains('queryMenstruationCycles', $tools);

        $day = GarminDailyMetric::where('user_id', $this->user->id)->whereDate('date', '2026-11-05')->first();
        $this->assertSame('coros', $day->source);
        $this->assertSame(64.0, $day->hrv);
        $this->assertSame(51, $day->resting_hr);
        $this->assertSame(80, $day->sleep_score);
        $this->assertSame(12345, $day->steps);

        $today = GarminDailyMetric::where('user_id', $this->user->id)->whereDate('date', '2026-11-06')->first();
        $this->assertSame(52.0, $today->vo2max);
        $this->assertNull($today->sleep_score);

        $this->assertTrue($this->user->fresh()->receivesHealthMetrics());
        $this->assertNotNull($this->user->corosConnection->fresh()->health_synced_at);
    }

    public function test_without_consent_nothing_is_read(): void
    {
        Http::fake();
        $this->user->corosConnection->update(['ai_consent_at' => null]);

        $this->assertNull(app(CorosHealthImporter::class)->sync($this->user->fresh()));
        Http::assertNothingSent();
        $this->assertFalse($this->user->fresh()->receivesHealthMetrics());
    }

    public function test_switching_off_revokes_and_deletes_only_coros_values(): void
    {
        GarminDailyMetric::create(['user_id' => $this->user->id, 'date' => '2026-11-01', 'source' => 'garmin', 'hrv' => 70]);
        GarminDailyMetric::create(['user_id' => $this->user->id, 'date' => '2026-11-05', 'source' => 'coros', 'hrv' => 64]);

        $this->actingAs($this->user)->patch(route('coros.data-import'), ['enabled' => false])->assertRedirect();

        $this->assertNull($this->user->corosConnection->fresh()->ai_consent_at);
        $this->assertSame(['garmin'], GarminDailyMetric::where('user_id', $this->user->id)->pluck('source')->all());
    }

    public function test_switching_on_needs_the_consent(): void
    {
        $this->user->corosConnection->update(['ai_consent_at' => null]);

        $this->actingAs($this->user)->patch(route('coros.data-import'), ['enabled' => true])->assertSessionHasErrors('ai_consent');
        $this->assertNull($this->user->corosConnection->fresh()->ai_consent_at);

        $this->actingAs($this->user)->patch(route('coros.data-import'), ['enabled' => true, 'ai_consent' => true])->assertRedirect();
        $this->assertNotNull($this->user->corosConnection->fresh()->ai_consent_at);
        Queue::assertPushed(\App\Jobs\SyncCorosHealthJob::class);
    }
}
