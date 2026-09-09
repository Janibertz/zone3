<?php

namespace App\Services\Observability;

use App\Models\PerfCommandRun;
use App\Models\PerfEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Die einzige Stelle, die Messwerte schreibt.
 *
 * Zwei Regeln, beide nicht verhandelbar:
 *
 * 1. **Eine Messung darf nie eine Anfrage kaputtmachen.** Alles laeuft in
 *    einem try/catch; scheitert das Schreiben, verschwindet der Messwert und
 *    sonst nichts. Ein Beobachter, der den Betrieb stoert, ist schlimmer als
 *    kein Beobachter.
 * 2. **Geschrieben wird nach der Antwort.** Der Aufruf kommt aus dem
 *    terminate-Callback der Middleware, nicht aus der Anfrage selbst.
 */
class Recorder
{
    public function __construct(private readonly Collector $collector) {}

    public function enabled(): bool
    {
        return (bool) config('observability.enabled', true);
    }

    // ── Anfragen ─────────────────────────────────────────────────────────

    public function request(Request $request, int $status, float $durationMs): void
    {
        if (! $this->enabled() || $this->ignored($request)) {
            return;
        }

        $isSlow     = $durationMs >= (float) config('observability.slow_request_ms', 1000);
        $isError    = $status >= 500;
        $queryCount = $this->collector->queryCount();

        // Langsames und Kaputtes wird immer aufgezeichnet. Ausgerechnet das
        // wegzuwerfen, wonach man spaeter sucht, waere die teuerste Art zu
        // sparen.
        if (! $isSlow && ! $isError && ! $this->sampled()) {
            return;
        }

        $this->write(function () use ($request, $status, $durationMs, $queryCount) {
            PerfEvent::create([
                'type'        => PerfEvent::TYPE_REQUEST,
                'name'        => $this->routeName($request),
                'duration_ms' => (int) round($durationMs),
                'status'      => (string) $status,
                'query_count' => $queryCount,
                'query_ms'    => (int) round($this->collector->queryMs()),
                'memory_kb'   => (int) round(memory_get_peak_usage(true) / 1024),
                'user_id'     => Auth::id(),
                'context'     => [
                    'method' => $request->method(),
                    'path'   => '/' . ltrim($request->path(), '/'),
                ],
            ]);

            // Die langsamen Queries dieser Anfrage — als eigene Zeilen,
            // damit sie sich ueber alle Anfragen hinweg gruppieren lassen.
            foreach ($this->collector->slowQueries() as $query) {
                PerfEvent::create([
                    'type'        => PerfEvent::TYPE_QUERY,
                    'name'        => $query['sql'],
                    'duration_ms' => (int) round($query['ms']),
                    'status'      => 'ok',
                    'user_id'     => Auth::id(),
                    'context'     => ['route' => $this->routeName($request)],
                ]);
            }
        });
    }

    // ── Queue-Jobs ───────────────────────────────────────────────────────

    public function job(string $name, string $queue, float $durationMs, ?string $failure = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        // Die Queries des Jobs, bevor der eigene Insert sie verfaelscht.
        $queryCount = $this->collector->queryCount();
        $queryMs    = (int) round($this->collector->queryMs());
        $slow       = $this->collector->slowQueries();

        $this->write(function () use ($name, $queue, $durationMs, $failure, $queryCount, $queryMs, $slow) {
            PerfEvent::create([
                'type'        => PerfEvent::TYPE_JOB,
                'name'        => mb_substr($name, 0, 191),
                'duration_ms' => (int) round($durationMs),
                'status'      => $failure ? 'failed' : 'ok',
                'query_count' => $queryCount,
                'query_ms'    => $queryMs,
                'context'     => array_filter([
                    'queue'     => $queue,
                    'exception' => $failure ? mb_substr($failure, 0, 300) : null,
                ]),
            ]);

            // Langsame Queries zaehlen im Hintergrund genauso — ein Job, der
            // dreissig Sekunden braucht, tut das oft nicht wegen OpenAI.
            foreach ($slow as $query) {
                PerfEvent::create([
                    'type'        => PerfEvent::TYPE_QUERY,
                    'name'        => $query['sql'],
                    'duration_ms' => (int) round($query['ms']),
                    'status'      => 'ok',
                    'context'     => ['job' => mb_substr($name, 0, 150)],
                ]);
            }
        });
    }

    // ── Ausgehende Aufrufe ───────────────────────────────────────────────

    /**
     * Host und Pfad, niemals der Query-String.
     *
     * In den Query-Strings stehen Zugangsdaten — `client_secret` bei Stravas
     * Subscription-Endpunkt ist das offensichtlichste Beispiel. Ein
     * Messwert ist kein Grund, ein Geheimnis in die Datenbank zu schreiben.
     */
    public function outgoing(string $url, ?int $status, float $durationMs, bool $failed = false): void
    {
        if (! $this->enabled()) {
            return;
        }

        $parts = parse_url($url) ?: [];
        $name  = ($parts['host'] ?? 'unbekannt') . ($parts['path'] ?? '');

        $this->write(fn () => PerfEvent::create([
            'type'        => PerfEvent::TYPE_HTTP,
            'name'        => mb_substr($name, 0, 191),
            'duration_ms' => (int) round($durationMs),
            'status'      => $failed ? 'failed' : (string) ($status ?? '0'),
        ]));
    }

    // ── Kommandos ────────────────────────────────────────────────────────

    /**
     * Fortschreiben statt anhaengen — eine Zeile pro Kommando.
     *
     * `push:wellbeing-reminders` laeuft jede Minute. Als Ereignisstrom
     * waeren das 43.000 Zeilen im Monat fuer eine Frage, die eine einzige
     * Zeile beantwortet.
     */
    public function command(string $command, float $durationMs, int $exitCode): void
    {
        if (! $this->enabled()) {
            return;
        }

        $ms      = (int) round($durationMs);
        $queries = $this->collector->queryCount();

        $this->write(function () use ($command, $ms, $exitCode, $queries) {
            $run = PerfCommandRun::firstOrNew(['command' => mb_substr($command, 0, 191)]);

            $run->last_run_at      = now();
            $run->last_duration_ms = $ms;
            $run->last_exit_code   = $exitCode;
            $run->last_query_count = $queries;
            $run->slowest_ms       = max($ms, (int) $run->slowest_ms);
            $run->runs_total       = (int) $run->runs_total + 1;
            $run->failures_total   = (int) $run->failures_total + ($exitCode === 0 ? 0 : 1);

            $run->save();
        });
    }

    // ── Innereien ────────────────────────────────────────────────────────

    /**
     * Schreiben, ohne dass die Messung sich selbst misst und ohne dass ein
     * Fehler nach aussen dringt.
     */
    private function write(callable $fn): void
    {
        $this->collector->mute();

        try {
            $fn();
        } catch (Throwable $e) {
            // Bewusst nur debug: eine volle Platte oder eine fehlende
            // Migration soll die Logs nicht fluten und schon gar nicht die
            // Anfrage betreffen.
            Log::debug('Messwert konnte nicht geschrieben werden', ['error' => $e->getMessage()]);
        } finally {
            $this->collector->unmute();
        }
    }

    private function sampled(): bool
    {
        $rate = (float) config('observability.sample_rate', 1.0);

        return $rate >= 1.0 || mt_rand() / mt_getrandmax() <= $rate;
    }

    private function ignored(Request $request): bool
    {
        $patterns = (array) config('observability.ignore_paths', []);

        return $patterns !== [] && $request->is(...$patterns);
    }

    /**
     * Das Route-MUSTER, nicht die konkrete URL.
     *
     * `GET events/{event}/plan` gruppiert sich; `GET /events/7/plan` waere
     * fuer jeden Event eine eigene Zeile und die Auswertung damit wertlos.
     */
    private function routeName(Request $request): string
    {
        $uri = $request->route()?->uri();

        if (! $uri) {
            return $request->method() . ' (ohne Route)';
        }

        return mb_substr($request->method() . ' /' . ltrim($uri, '/'), 0, 191);
    }
}
