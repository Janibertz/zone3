<?php

namespace Tests\Feature;

use App\Models\CorosConnection;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `coros:probe` sammelt Rohantworten als Vorlage fuer den Import; nur der
 * Admin kommt an die Dateien.
 */
class CorosProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 12:00');
        Storage::fake('local');
        config(['services.coros.enabled' => true, 'services.coros.base_url' => 'https://mcpeu.coros.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function connected(): User
    {
        $user = User::factory()->onboarded()->create();
        CorosConnection::create([
            'user_id' => $user->id, 'client_id' => 'c', 'access_token' => 'tok',
            'refresh_token' => 'ref', 'access_token_expires_at' => now()->addDays(20),
        ]);

        return $user;
    }

    public function test_it_stores_the_raw_answers_and_follows_the_first_activity(): void
    {
        $user  = $this->connected();
        $tools = [];

        Http::fake([
            'mcpeu.coros.com/mcp' => function (Request $request) use (&$tools) {
                $tool    = $request['params']['name'];
                $tools[] = $tool;
                $text    = match ($tool) {
                    'querySportRecords'                => "1. Morning Run\nlabelId: 476123456789\nsportType: 100",
                    'queryActivityFitFileDownloadUrls' => 'https://files.coros.com/a.fit',
                    default                            => "{$tool} ok",
                };

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => $text]]]]);
            },
            'files.coros.com/*' => Http::response('FITDATA'),
        ]);

        $this->artisan('coros:probe', ['user' => $user->id])->assertSuccessful();

        $this->assertContains('getActivityDetail', $tools);
        $this->assertContains('querySleepHrv', $tools);

        $files = Storage::disk('local')->files('coros-probe');
        $this->assertCount(2, $files);
        $txt = collect($files)->first(fn ($f) => str_ends_with($f, '.txt'));
        $this->assertStringContainsString('labelId: 476123456789', Storage::disk('local')->get($txt));
    }

    public function test_only_admins_get_the_files(): void
    {
        Storage::disk('local')->put('coros-probe/user1_2026-11-06_120000.txt', 'roh');

        $this->actingAs(User::factory()->onboarded()->create())
            ->get('/admin/coros-probe/user1_2026-11-06_120000.txt')->assertForbidden();

        $admin = User::factory()->onboarded()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/coros-probe')->assertOk()->assertSee('user1_2026-11-06_120000.txt');
        $this->actingAs($admin)->get('/admin/coros-probe/user1_2026-11-06_120000.txt')->assertOk();
        $this->actingAs($admin)->get('/admin/coros-probe/..%2F.env')->assertNotFound();
    }
}
