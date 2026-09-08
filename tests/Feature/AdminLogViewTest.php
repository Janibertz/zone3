<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Das Anwendungslog im Admin-Bereich.
 *
 * Dreimal in einer Woche hing eine Fehlersuche daran, dass die Antwort im
 * Log stand und niemand drankam. „Ruft Strava überhaupt an?" hat zwei
 * lange Sitzungen gekostet und war die ganze Zeit eine Zeile entfernt.
 */
class AdminLogViewTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        // `LogReader` nimmt die zuletzt geschriebene Datei — diese hier.
        $this->file = storage_path('logs/laravel-phpunit-' . uniqid() . '.log');
        @mkdir(dirname($this->file), 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function write(string $content): void
    {
        file_put_contents($this->file, $content);
        touch($this->file, time() + 60); // sicher die neueste
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Zugang ───────────────────────────────────────────────────────────

    public function test_a_normal_user_cannot_read_the_log(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/system/logs')
            ->assertForbidden();
    }

    public function test_an_admin_can(): void
    {
        $this->write("[2026-09-08 09:50:49] production.INFO: Strava-Webhook empfangen {\"owner_id\":4711}\n");

        $this->actingAs($this->admin())->get('/admin/system/logs')->assertOk();
    }

    // ── Das Lesen ────────────────────────────────────────────────────────

    public function test_an_entry_is_broken_into_its_parts(): void
    {
        $this->write("[2026-09-08 09:50:49] production.INFO: Strava-Webhook empfangen {\"owner_id\":4711,\"object_id\":998877}\n");

        $entry = app(LogReader::class)->tail()['entries'][0];

        $this->assertSame('2026-09-08 09:50:49', $entry['time']);
        $this->assertSame('INFO', $entry['level']);
        $this->assertSame('Strava-Webhook empfangen', $entry['message']);
        $this->assertStringContainsString('4711', $entry['context'], 'Der Kontext ist die eigentliche Information');
    }

    /**
     * Ein Stacktrace bringt hundert Folgezeilen mit. Sie gehören zum
     * Eintrag davor — sonst besteht die Ansicht aus Rauschen.
     */
    public function test_a_stack_trace_stays_with_its_entry(): void
    {
        $this->write(
            "[2026-09-08 09:00:00] production.ERROR: Strava-Import fehlgeschlagen {\"user_id\":1}\n"
            . "#0 /app/vendor/laravel/framework/x.php(12): foo()\n"
            . "#1 /app/vendor/laravel/framework/y.php(34): bar()\n"
            . "#2 {main}\n"
        );

        $result = app(LogReader::class)->tail();

        $this->assertCount(1, $result['entries'], 'Der Stacktrace ist kein eigener Eintrag');
        $this->assertSame(3, $result['entries'][0]['trace']);
    }

    public function test_the_newest_entry_comes_first(): void
    {
        $this->write(
            "[2026-09-08 08:00:00] production.INFO: zuerst\n"
            . "[2026-09-08 09:00:00] production.INFO: danach\n"
        );

        $entries = app(LogReader::class)->tail()['entries'];

        $this->assertSame('danach', $entries[0]['message']);
        $this->assertSame('zuerst', $entries[1]['message']);
    }

    // ── Filter ───────────────────────────────────────────────────────────

    public function test_the_search_looks_in_message_and_context(): void
    {
        $this->write(
            "[2026-09-08 09:00:00] production.INFO: Strava-Webhook empfangen {\"owner_id\":4711}\n"
            . "[2026-09-08 09:01:00] production.INFO: Tagesnachricht erzeugt {\"user_id\":9}\n"
        );

        $reader = app(LogReader::class);

        $this->assertCount(1, $reader->tail(search: 'Strava')['entries']);
        $this->assertCount(1, $reader->tail(search: '4711')['entries'], 'Auch der Kontext wird durchsucht');
        $this->assertCount(0, $reader->tail(search: 'Garmin')['entries']);
    }

    public function test_the_level_can_be_narrowed(): void
    {
        $this->write(
            "[2026-09-08 09:00:00] production.INFO: harmlos\n"
            . "[2026-09-08 09:01:00] production.ERROR: kaputt\n"
        );

        $errors = app(LogReader::class)->tail(level: 'error')['entries'];

        $this->assertCount(1, $errors);
        $this->assertSame('kaputt', $errors[0]['message']);
    }

    // ── Grenzen ──────────────────────────────────────────────────────────

    /**
     * Die Datei wächst unbegrenzt. Sie ganz einzulesen wäre dieselbe Sorte
     * Fehler wie der synchrone Webhook — deshalb nur ein Fenster vom Ende.
     */
    public function test_a_huge_file_is_read_from_the_end_only(): void
    {
        $line  = "[2026-09-08 09:00:00] production.INFO: Fuellzeile\n";
        $bulk  = str_repeat($line, (int) ceil((LogReader::WINDOW_BYTES * 2) / strlen($line)));

        $this->write($bulk . "[2026-09-08 10:00:00] production.INFO: DIE LETZTE ZEILE\n");

        $result = app(LogReader::class)->tail(search: 'DIE LETZTE ZEILE');

        $this->assertTrue($result['truncated'], 'Es darf nicht die ganze Datei gelesen werden');
        $this->assertCount(1, $result['entries']);
        $this->assertGreaterThan(LogReader::WINDOW_BYTES, $result['size']);
    }

    public function test_no_log_file_is_not_a_crash(): void
    {
        // Diesen Test ohne eigene Datei: die Ansicht muss auch dann tragen.
        @unlink($this->file);

        $this->actingAs($this->admin())->get('/admin/system/logs')->assertOk();
    }
}
