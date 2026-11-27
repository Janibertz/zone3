<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\CorosConnection;
use App\Models\User;
use App\Services\Coros\CorosClient;
use App\Services\Coros\CorosOutage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Am 27.11.2026 lief `coros:sync-activities` in einen DNS-Timeout
 * („Resolving timed out after 10000 milliseconds“) und meldete ihn als Error
 * — mit Sofort-Push an die Admins. Ein Aussetzer wird jetzt wiederholt, ein
 * anhaltender erst ab dem dritten Lauf in Folge gemeldet.
 */
class CorosNetworkHiccupTest extends TestCase
{
    use RefreshDatabase;

    private const DNS = 'cURL error 28: Resolving timed out after 10000 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://mcpeu.coros.com/mcp';

    private User $user;

    /** So viele MCP-Aufrufe scheitern noch am DNS. */
    private int $failures = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 12:00:00');
        Queue::fake();
        Sleep::fake();
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

    private function list(): string
    {
        $start = now()->subHour()->timestamp;
        $end   = $start + 1860;

        return <<<TXT
            Sport Records — 2026-11-04 to 2026-11-06 (1 records)
            ========================

            1. Outdoor Run — 2026-11-06
               Time Window: startTimestamp={$start} | endTimestamp={$end}
               Duration: 30:00 | Distance: 6.00 km
               Average Pace: 5:00 /km | Avg HR: 150 bpm | Calories: 400 kcal
               LabelId: 400000000000000001 | SportType: 100
            TXT;
    }

    /** Die ersten `$failures` MCP-Aufrufe scheitern am DNS, danach antwortet COROS. */
    private function fake(int $failures, array &$calls = []): void
    {
        $this->failures = $failures;
        $list = $this->list();
        $fit  = CorosActivityImportTest::fit(now()->subHour()->timestamp);

        Http::fake([
            'mcpeu.coros.com/mcp' => function (Request $request) use (&$calls, $list) {
                $calls[] = $request['params']['name'];
                if ($this->failures-- > 0) {
                    return Http::failedConnection(self::DNS)($request);
                }

                $text = match ($request['params']['name']) {
                    'querySportRecords'                => $list,
                    'queryActivityFitFileDownloadUrls' => "1. x.fit\n   https://s3eu.coros.com/fit/v2/1/x.fit",
                    default                            => '',
                };

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => $text]]]]);
            },
            's3eu.coros.com/*' => Http::response($fit),
        ]);
    }

    public function test_a_dns_hiccup_is_retried_and_the_run_arrives(): void
    {
        Exceptions::fake();
        $calls = [];
        $this->fake(1, $calls);

        $this->artisan('coros:sync-activities')->assertSuccessful();

        $this->assertSame(['querySportRecords', 'querySportRecords'], array_slice($calls, 0, 2));
        $this->assertSame(1, Activity::where('user_id', $this->user->id)->count());
        Exceptions::assertNothingReported();
    }

    public function test_a_lasting_outage_is_a_warning_until_the_third_run(): void
    {
        Exceptions::fake();
        Log::spy();
        $this->fake(PHP_INT_MAX);

        $this->artisan('coros:sync-activities')->assertSuccessful();
        $this->artisan('coros:sync-activities')->assertSuccessful();
        Exceptions::assertNothingReported();
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'nicht erreichbar'))->twice();

        $this->artisan('coros:sync-activities')->assertSuccessful();
        Exceptions::assertReportedCount(1);
    }

    public function test_a_successful_run_resets_the_count(): void
    {
        Exceptions::fake();
        // Je Lauf: erster Aufruf plus zwei Wiederholungen = 3 Fehlschläge.
        // Zwei Läufe gestört, einer gut, dann wieder einer gestört.
        $calls = [];
        $this->fake(6, $calls);
        $this->artisan('coros:sync-activities');
        $this->artisan('coros:sync-activities');
        $this->artisan('coros:sync-activities');
        $this->assertSame(1, Activity::where('user_id', $this->user->id)->count());

        $this->failures = 3; // ein ganzer Lauf gestört: ohne Zurücksetzen wäre es der dritte
        $this->artisan('coros:sync-activities');
        Exceptions::assertNothingReported();
    }

    public function test_a_write_is_only_retried_when_nothing_was_sent(): void
    {
        $this->assertTrue(CorosClient::beforeSend(new \RuntimeException(self::DNS)));
        $this->assertTrue(CorosClient::beforeSend(new \RuntimeException('cURL error 7: Failed to connect to mcpeu.coros.com port 443')));
        $this->assertFalse(CorosClient::beforeSend(new \RuntimeException('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received')));
    }

    public function test_a_rejected_token_is_reported_at_once(): void
    {
        Exceptions::fake();
        CorosOutage::failed(new \App\Services\Coros\CorosException('COROS hat den Token abgelehnt', 400), $this->user->id, 'Kalender');
        Exceptions::assertReportedCount(1);
    }

    public function test_a_network_failure_during_refresh_does_not_mark_the_connection_broken(): void
    {
        $connection = $this->user->corosConnection;
        $connection->update(['access_token_expires_at' => now()->addHour()]);
        Http::fake(['mcpeu.coros.com/oauth2/token' => Http::failedConnection(self::DNS)]);

        try {
            app(CorosClient::class)->refresh($connection);
            $this->fail('erwartet: COROS nicht erreichbar');
        } catch (\App\Services\Coros\CorosException $e) {
            $this->assertTrue(CorosClient::transient($e));
        }

        $this->assertNull($connection->fresh()->refresh_error);
    }
}
