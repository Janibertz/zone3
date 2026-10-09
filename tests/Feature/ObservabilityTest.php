<?php

namespace Tests\Feature;

use App\Models\PerfAlert;
use App\Models\PerfCommandRun;
use App\Models\User;
use App\Services\Observability\Watchdog;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Der Waechter — was von der Selbstbeobachtung uebrig blieb.
 *
 * Gewuenscht war einmal: „Ich finde Logs und auch Queries super wichtig
 * auch auf den Hintergrund für Performance und auch Fehlersuche." Daraus
 * wurden drei Tabellen, eine Middleware, vier Dienste und eine eigene
 * Admin-Seite — und in einem Monat Betrieb 51 Messpunkte. Bei zwei aktiven
 * Athleten gibt es keine Lastprobleme zu messen; die Anfrage-Messung ist
 * deshalb abgebaut.
 *
 * Geblieben ist die Achse, auf der Zone3 wirklich kaputtging: sechs Tage
 * ohne Strava-Import, bemerkt vom Athleten selbst. Dafuer braucht es eine
 * Zeile pro Kommando und jemanden, der sich meldet.
 *
 * Die Tests sichern die Zusagen, die man einem Waechter nicht ansieht:
 * dass eine Zeile fortgeschrieben und nicht angehaengt wird, und dass eine
 * Warnung nicht bei jedem Lauf erneut auf demselben Telefon landet.
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

    // ── Kommandos ────────────────────────────────────────────────────────

    /**
     * `push:wellbeing-reminders` laeuft jede Minute. Als Ereignisstrom waere
     * das 43.000 Zeilen im Monat fuer eine Frage, die eine einzige Zeile
     * beantwortet.
     */
    public function test_a_command_is_written_forward_not_appended(): void
    {
        Artisan::call('perf:watch', ['--dry' => true]);
        Artisan::call('perf:watch', ['--dry' => true]);

        $runs = PerfCommandRun::where('command', 'perf:watch')->get();

        $this->assertCount(1, $runs, 'Eine Zeile pro Kommando, nicht eine pro Lauf');
        $this->assertSame(2, $runs->first()->runs_total);
        $this->assertNotNull($runs->first()->last_run_at);
    }

    /** Der Schalter muss wirken, ohne dass jemand Code anfasst. */
    public function test_nothing_is_recorded_when_switched_off(): void
    {
        config(['observability.enabled' => false]);

        Artisan::call('perf:watch', ['--dry' => true]);

        $this->assertSame(0, PerfCommandRun::count());
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

    public function test_a_failed_job_is_a_finding_with_its_class(): void
    {
        \DB::table('failed_jobs')->insert([
            'uuid'       => \Str::uuid()->toString(),
            'connection' => 'database',
            'queue'      => 'default',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\SendNewsletterJob']),
            'exception'  => 'ErrorException: Undefined variable $subject',
            'failed_at'  => now(),
        ]);

        $findings = app(Watchdog::class)->findings();
        $byKey    = array_column($findings, 'message', 'key');

        $this->assertArrayHasKey('failed_jobs', $byKey);
        $this->assertStringContainsString('SendNewsletterJob', $byKey['failed_jobs']);
    }

    /**
     * Der Waechter laeuft taeglich. Ohne Abklingzeit schickte er denselben
     * Satz bei jedem Lauf erneut, und nach einer Woche liest ihn niemand
     * mehr.
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

    // ── Wo die Befunde landen ────────────────────────────────────────────

    /**
     * Die eigene Admin-Seite ist weg; die Befunde stehen jetzt auf
     * `/admin/system`, wohin auch die Push-Nachricht zeigt. Eine zweite
     * Seite neben der Systemseite war genau die Sorte Doppelung, die dieses
     * Projekt teuer bezahlt hat.
     */
    public function test_the_system_page_shows_the_findings(): void
    {
        config(['observability.expected_every_minutes' => ['strava:sync' => 45]]);

        PerfCommandRun::create([
            'command'     => 'strava:sync',
            'last_run_at' => now()->subHours(3),
            'runs_total'  => 100,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.system.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/System/Index')
                ->where('watchdog.0.key', 'command_overdue:strava:sync')
            );
    }

    /**
     * Ein Seitenaufruf darf keine Nachricht verbrauchen — `findings()` ist
     * ohne Nebenwirkung, `run()` schreibt die Abklingzeit fort.
     */
    public function test_looking_at_the_page_does_not_consume_a_notification(): void
    {
        config(['observability.expected_every_minutes' => ['strava:sync' => 45]]);

        PerfCommandRun::create(['command' => 'strava:sync', 'last_run_at' => now()->subHours(3)]);

        $this->actingAs($this->admin())->get(route('admin.system.index'))->assertOk();

        $this->assertSame(0, PerfAlert::count(), 'Ansehen schreibt keinen Alarm fort');
    }

    public function test_only_admins_may_look(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('admin.system.index'))
            ->assertForbidden();
    }
}
