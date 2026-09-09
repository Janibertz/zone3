<?php

namespace Tests\Feature;

use App\Models\PerfAlert;
use App\Models\PerfCommandRun;
use App\Models\PerfEvent;
use App\Models\User;
use App\Services\Observability\Collector;
use App\Services\Observability\Recorder;
use App\Services\Observability\Watchdog;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Die Messung des eigenen Betriebs.
 *
 * Gewuenscht: „Ich finde Logs und auch Queries super wichtig auch auf den
 * Hintergrund für Performance und auch Fehlersuche."
 *
 * Die Tests hier sichern vor allem die Zusagen ab, die man einer Messung
 * nicht ansieht: dass sie den Betrieb nicht stoert, dass sie keine
 * Gesundheitsdaten mitschreibt, und dass eine Warnung nicht viermal pro
 * Stunde auf demselben Telefon landet.
 */
class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    // Ohne das feuern CommandStarting/CommandFinished im Test nicht: sie
    // werden aus Symfonys Console-Dispatcher umgeleitet, und der wird nur in
    // `Kernel::handle()` verdrahtet — also beim echten `php artisan`, so wie
    // der Scheduler jedes Kommando startet.
    use WithConsoleEvents;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Anfragen ─────────────────────────────────────────────────────────

    public function test_a_request_is_recorded_once_with_its_query_count(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.performance.index'))->assertOk();

        $events = PerfEvent::where('type', PerfEvent::TYPE_REQUEST)->get();

        $this->assertCount(1, $events, 'Eine Anfrage, eine Zeile — nicht eine pro Query');

        $event = $events->first();

        $this->assertSame('GET /admin/performance', $event->name, 'Das Route-Muster, nicht die URL');
        $this->assertSame('200', $event->status);
        $this->assertGreaterThan(0, $event->query_count, 'Die Queries werden mitgezaehlt');
        $this->assertSame($admin->id, $event->user_id);
    }

    /**
     * Der Health-Check von Coolify schlaegt im Minutentakt auf. Aufgezeichnet
     * fuellte er die Tabelle und beantwortete keine einzige Frage.
     */
    public function test_ignored_paths_are_not_recorded(): void
    {
        config(['observability.ignore_paths' => ['admin/performance']]);

        $this->actingAs($this->admin())->get(route('admin.performance.index'))->assertOk();

        $this->assertSame(0, PerfEvent::where('type', PerfEvent::TYPE_REQUEST)->count());
    }

    public function test_nothing_is_recorded_when_switched_off(): void
    {
        config(['observability.enabled' => false]);

        $this->actingAs($this->admin())->get(route('admin.performance.index'))->assertOk();

        $this->assertSame(0, PerfEvent::count());
    }

    // ── Queries ──────────────────────────────────────────────────────────

    public function test_only_slow_queries_are_kept(): void
    {
        config(['observability.slow_query_ms' => 100]);

        $collector = new Collector();
        $collector->query('select * from users', 5.0);
        $collector->query('select * from activities', 250.0);

        $this->assertSame(2, $collector->queryCount(), 'Gezaehlt werden alle');
        $this->assertCount(1, $collector->slowQueries(), 'Gespeichert nur die langsamen');
    }

    /**
     * Die wichtigste Zusicherung der ganzen Ablage.
     *
     * Durch Zone3 laufen HRV, Schlaf und Ruhepuls. Laravel liefert das SQL
     * mit `?` statt der Werte, und genau so bleibt es — eine Messtabelle ist
     * kein Ort fuer Gesundheitsdaten.
     */
    public function test_values_never_reach_the_measurement_table(): void
    {
        $normalized = Collector::normalizeSql(
            "select *  from  garmin_daily_metrics where user_id = ? and hrv = ? and id in (?, ?, ?)"
        );

        $this->assertStringNotContainsString('42', $normalized);
        $this->assertStringContainsString('in (?)', $normalized, 'Listen werden zusammengefasst');
        $this->assertStringNotContainsString('  ', $normalized, 'Whitespace wird normalisiert');
    }

    public function test_the_measurement_does_not_measure_itself(): void
    {
        $collector = new Collector();
        $collector->query('insert into perf_events (type, name) values (?, ?)', 5.0);

        $this->assertSame(0, $collector->queryCount());
    }

    // ── Ausgehende Aufrufe ───────────────────────────────────────────────

    /**
     * Im Query-String von Stravas Subscription-Endpunkt steht das
     * `client_secret`. Ein Messwert ist kein Grund, ein Geheimnis in die
     * Datenbank zu schreiben.
     */
    public function test_an_outgoing_call_is_recorded_without_its_query_string(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        Http::get('https://www.strava.com/api/v3/push_subscriptions', [
            'client_id'     => '123',
            'client_secret' => 'streng-geheim',
        ]);

        $event = PerfEvent::where('type', PerfEvent::TYPE_HTTP)->firstOrFail();

        $this->assertSame('www.strava.com/api/v3/push_subscriptions', $event->name);
        $this->assertSame('200', $event->status);
        $this->assertStringNotContainsString('streng-geheim', $event->name);
    }

    // ── Kommandos ────────────────────────────────────────────────────────

    /**
     * `push:wellbeing-reminders` laeuft jede Minute. Als Ereignisstrom waeren
     * das 43.000 Zeilen im Monat fuer eine Frage, die eine einzige Zeile
     * beantwortet.
     */
    public function test_a_command_is_written_forward_not_appended(): void
    {
        Artisan::call('perf:prune');
        Artisan::call('perf:prune');

        $runs = PerfCommandRun::where('command', 'perf:prune')->get();

        $this->assertCount(1, $runs, 'Eine Zeile pro Kommando, nicht eine pro Lauf');
        $this->assertSame(2, $runs->first()->runs_total);
        $this->assertNotNull($runs->first()->last_run_at);
    }

    // ── Jobs ─────────────────────────────────────────────────────────────

    public function test_a_failed_job_keeps_the_first_line_of_its_exception(): void
    {
        app(Recorder::class)->job(
            'App\\Jobs\\GenerateSessionReviewJob',
            'default',
            1234.0,
            'OpenAI antwortete nicht',
        );

        $event = PerfEvent::where('type', PerfEvent::TYPE_JOB)->firstOrFail();

        $this->assertSame('failed', $event->status);
        $this->assertSame(1234, $event->duration_ms);
        $this->assertSame('OpenAI antwortete nicht', $event->context['exception']);
    }

    // ── Der Waechter ─────────────────────────────────────────────────────

    public function test_an_overdue_command_is_found(): void
    {
        config(['observability.expected_every_minutes' => ['strava:sync' => 45]]);

        PerfCommandRun::create([
            'command'     => 'strava:sync',
            'last_run_at' => now()->subHours(3),
            'runs_total'  => 100,
        ]);

        $findings = app(Watchdog::class)->findings();

        $this->assertContains('command_overdue:strava:sync', array_column($findings, 'key'));
    }

    public function test_a_command_within_its_window_is_not_a_finding(): void
    {
        config(['observability.expected_every_minutes' => ['strava:sync' => 45]]);

        PerfCommandRun::create([
            'command'     => 'strava:sync',
            'last_run_at' => now()->subMinutes(10),
            'runs_total'  => 100,
        ]);

        $this->assertSame([], app(Watchdog::class)->findings());
    }

    /**
     * Der Waechter laeuft alle 15 Minuten. Ohne Abklingzeit schickte er
     * denselben Satz viermal pro Stunde, und nach einem Tag liest ihn
     * niemand mehr.
     */
    public function test_the_same_finding_is_pushed_only_once(): void
    {
        $this->admin();

        config([
            'observability.expected_every_minutes'  => ['strava:sync' => 45],
            'observability.alerts.cooldown_hours'   => 6,
        ]);

        PerfCommandRun::create(['command' => 'strava:sync', 'last_run_at' => now()->subHours(3)]);

        $push = $this->mock(WebPushService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendToUser')->once();
        });

        $watchdog = app(Watchdog::class);

        $first  = $watchdog->run();
        $second = $watchdog->run();

        $this->assertTrue($first[0]['notified']);
        $this->assertFalse($second[0]['notified'], 'Der zweite Lauf schweigt');

        unset($push);
    }

    /**
     * Loest sich ein Befund auf und kommt wieder, ist das eine neue
     * Nachricht wert — sonst bliebe ein echter Rueckfall sechs Stunden lang
     * still.
     */
    public function test_a_returning_finding_is_pushed_again(): void
    {
        $this->admin();

        config(['observability.expected_every_minutes' => ['strava:sync' => 45]]);

        $this->mock(WebPushService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendToUser')->twice();
        });

        $run      = PerfCommandRun::create(['command' => 'strava:sync', 'last_run_at' => now()->subHours(3)]);
        $watchdog = app(Watchdog::class);

        $watchdog->run();

        // Das Kommando laeuft wieder — der Befund verschwindet.
        $run->update(['last_run_at' => now()]);
        $watchdog->run();

        $this->assertNotNull(PerfAlert::where('key', 'command_overdue:strava:sync')->first()->resolved_at);

        // Und faellt erneut aus.
        $run->update(['last_run_at' => now()->subHours(3)]);
        $again = $watchdog->run();

        $this->assertTrue($again[0]['notified']);
    }

    // ── Aufraeumen ───────────────────────────────────────────────────────

    /**
     * Die Logdatei in Produktion ist ohne Rotation gewachsen, bis niemand
     * sie mehr lesen konnte. Eine Zeile pro Anfrage waechst schneller.
     */
    public function test_pruning_removes_old_measurements_and_keeps_recent_ones(): void
    {
        PerfEvent::create(['type' => 'request', 'name' => 'alt',  'duration_ms' => 10])
            ->forceFill(['created_at' => now()->subDays(30)])->save();

        PerfEvent::create(['type' => 'request', 'name' => 'neu', 'duration_ms' => 10]);

        Artisan::call('perf:prune', ['--days' => 14]);

        $this->assertSame(1, PerfEvent::where('type', 'request')->count());
        $this->assertSame('neu', PerfEvent::where('type', 'request')->first()->name);
    }

    // ── Zugriff ──────────────────────────────────────────────────────────

    public function test_only_admins_may_look(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('admin.performance.index'))
            ->assertForbidden();
    }

    public function test_the_page_shows_what_was_measured(): void
    {
        PerfEvent::create([
            'type' => 'request', 'name' => 'GET /dashboard', 'duration_ms' => 890,
            'status' => '200', 'query_count' => 213, 'query_ms' => 400,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.performance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Performance/Index')
                ->where('routes.0.name', 'GET /dashboard')
                ->where('routes.0.max_queries', 213)
                ->where('routes.0.suspect_n1', true)
            );
    }
}
