<?php

namespace App\Services\Observability;

/**
 * Was waehrend EINER Anfrage passiert ist — im Speicher, nicht in der
 * Datenbank.
 *
 * Der Webprozess ist `php artisan serve` und damit single-threaded. Jede
 * Query sofort wegzuschreiben hiesse, die Anzahl der Schreibvorgaenge zu
 * verdoppeln und den einen Prozess auszubremsen, der alle Athleten
 * bedient — dieselbe Klasse Fehler wie der synchrone Webhook und das
 * `file_get_contents` auf der Logdatei.
 *
 * Deshalb: hier sammeln, im terminate-Callback einmal schreiben.
 */
class Collector
{
    private float $startedAt;

    private int $queryCount = 0;
    private float $queryMs  = 0.0;

    /** @var list<array{sql: string, ms: float}> */
    private array $slowQueries = [];

    /** @var list<float> Startzeiten laufender ausgehender Aufrufe (LIFO). */
    private array $outgoingStarts = [];

    /**
     * Solange gesetzt, wird nichts gezaehlt. Schuetzt davor, dass die
     * Messung sich selbst misst, wenn der Recorder schreibt.
     */
    private bool $muted = false;

    public function __construct()
    {
        $this->startedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
    }

    /**
     * Von vorn zaehlen.
     *
     * Im Webprozess gilt ein Sammler fuer eine Anfrage. Im Queue-Worker und
     * im Scheduler laeuft derselbe Prozess stundenlang und arbeitet einen
     * Job nach dem anderen ab — ohne Zuruecksetzen waere die Query-Zahl des
     * zehnten Jobs die Summe aller zehn.
     */
    public function reset(): void
    {
        $this->startedAt   = microtime(true);
        $this->queryCount  = 0;
        $this->queryMs     = 0.0;
        $this->slowQueries = [];
        $this->muted       = false;
    }

    // ── Queries ──────────────────────────────────────────────────────────

    public function query(string $sql, float $ms): void
    {
        if ($this->muted || $this->touchesOwnTables($sql)) {
            return;
        }

        $this->queryCount++;
        $this->queryMs += $ms;

        if ($ms >= (float) config('observability.slow_query_ms', 100)) {
            // Nur die zehn langsamsten pro Anfrage. Eine Anfrage mit
            // zweihundert langsamen Queries hat ein Problem, das die
            // ersten zehn schon zeigen.
            if (count($this->slowQueries) < 10) {
                $this->slowQueries[] = ['sql' => self::normalizeSql($sql), 'ms' => $ms];
            }
        }
    }

    // ── Ausgehende Aufrufe ───────────────────────────────────────────────

    public function outgoingStarted(): void
    {
        $this->outgoingStarts[] = microtime(true);
    }

    /** Dauer des zuletzt begonnenen Aufrufs in Millisekunden. */
    public function outgoingFinished(): float
    {
        $start = array_pop($this->outgoingStarts);

        return $start ? (microtime(true) - $start) * 1000 : 0.0;
    }

    // ── Schalter ─────────────────────────────────────────────────────────

    public function mute(): void
    {
        $this->muted = true;
    }

    public function unmute(): void
    {
        $this->muted = false;
    }

    // ── Auslesen ─────────────────────────────────────────────────────────

    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function queryMs(): float
    {
        return $this->queryMs;
    }

    /** @return list<array{sql: string, ms: float}> */
    public function slowQueries(): array
    {
        return $this->slowQueries;
    }

    // ── Aufbereitung ─────────────────────────────────────────────────────

    /**
     * Aus einer konkreten Query wird ein Muster.
     *
     * Laravel liefert das SQL bereits mit `?` statt der Werte, und genau so
     * bleibt es: die Bindings enthalten Gesundheitsdaten — HRV, Schlaf,
     * Ruhepuls —, und die haben in einer Messtabelle nichts zu suchen.
     *
     * Zusaetzlich werden Listen zusammengefasst, sonst waere jedes
     * `whereIn` mit anderer Laenge eine eigene Zeile in der Auswertung.
     */
    public static function normalizeSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $sql = preg_replace('/\bin \((?:\s*\?\s*,)+\s*\?\s*\)/i', 'in (?)', $sql) ?? $sql;

        return mb_substr($sql, 0, 191);
    }

    /**
     * Die eigenen Tabellen werden nicht mitgezaehlt.
     *
     * Ohne das misst die Messung sich selbst: der Insert am Ende der
     * Anfrage taucht als Query auf, das Aufraeumkommando als Last.
     */
    private function touchesOwnTables(string $sql): bool
    {
        return str_contains($sql, 'perf_events')
            || str_contains($sql, 'perf_command_runs')
            || str_contains($sql, 'perf_alerts');
    }
}
