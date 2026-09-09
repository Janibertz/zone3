<?php

namespace App\Services\Observability;

use App\Models\PerfCommandRun;
use App\Models\PerfEvent;
use Illuminate\Support\Collection;

/**
 * Die Lesesseite: aus Messwerten werden Antworten.
 *
 * Zwei Dinge sind hier bewusst so und nicht anders:
 *
 * **Gruppiert wird ueber `name`, nicht ueber die konkrete URL.** Der
 * Recorder schreibt das Route-Muster; erst dadurch stehen achtzehn Aufrufe
 * von `/events/{event}/plan` in einer Zeile statt in achtzehn.
 *
 * **Keine Perzentile.** `avg` und `max` gibt es unter MySQL und SQLite
 * gleichermassen, ein p95 nicht — und die Tests laufen auf SQLite. Lieber
 * zwei Zahlen, die ueberall dasselbe bedeuten, als eine dritte, die unter
 * Test etwas anderes tut als in Produktion.
 */
class PerfDigest
{
    /** Auswahl fuer den Zeitraum-Umschalter: Beschriftung => Stunden. */
    public const WINDOWS = ['1 h' => 1, '24 h' => 24, '7 Tage' => 168];

    /**
     * Anfragen nach Route.
     *
     * `max_queries` ist die interessanteste Spalte: eine Route mit im Schnitt
     * 40 und maximal 213 Queries hat kein Performance-, sondern ein
     * N+1-Problem, und das sieht man nur am Ausreisser.
     */
    public function routes(int $hours, int $limit = 25): array
    {
        $many = (int) config('observability.many_queries', 60);

        return $this->grouped(PerfEvent::TYPE_REQUEST, $hours, $limit)
            ->map(fn ($row) => [
                'name'        => $row->name,
                'hits'        => (int) $row->hits,
                'avg_ms'      => (int) round((float) $row->avg_ms),
                'max_ms'      => (int) $row->max_ms,
                'avg_queries' => (int) round((float) $row->avg_queries),
                'max_queries' => (int) $row->max_queries,
                'errors'      => (int) $row->errors,
                'suspect_n1'  => (int) $row->max_queries >= $many,
            ])
            ->all();
    }

    /**
     * Langsame Queries, zusammengefasst.
     *
     * Woher sie kommen, steht im Kontext der einzelnen Zeile — dafuer gibt
     * es eine zweite Abfrage statt JSON-Extraktion im SQL, die unter MySQL
     * und SQLite verschieden geschrieben wird.
     */
    public function slowQueries(int $hours, int $limit = 20): array
    {
        $rows = $this->grouped(PerfEvent::TYPE_QUERY, $hours, $limit);

        if ($rows->isEmpty()) {
            return [];
        }

        $origins = PerfEvent::query()
            ->where('type', PerfEvent::TYPE_QUERY)
            ->whereIn('name', $rows->pluck('name')->all())
            ->latest('id')
            ->get(['name', 'context'])
            ->groupBy('name')
            ->map(fn ($group) => $group->first()->context ?? []);

        return $rows->map(function ($row) use ($origins) {
            $origin = $origins[$row->name] ?? [];

            return [
                'sql'    => $row->name,
                'hits'   => (int) $row->hits,
                'avg_ms' => (int) round((float) $row->avg_ms),
                'max_ms' => (int) $row->max_ms,
                'origin' => $origin['route'] ?? $origin['job'] ?? null,
            ];
        })->all();
    }

    /** Queue-Jobs: Dauer, Fehlschlaege und die Queries, die sie verursachen. */
    public function jobs(int $hours, int $limit = 20): array
    {
        return $this->grouped(PerfEvent::TYPE_JOB, $hours, $limit)
            ->map(fn ($row) => [
                'name'        => class_basename($row->name),
                'full_name'   => $row->name,
                'hits'        => (int) $row->hits,
                'avg_ms'      => (int) round((float) $row->avg_ms),
                'max_ms'      => (int) $row->max_ms,
                'avg_queries' => (int) round((float) $row->avg_queries),
                'failures'    => (int) $row->failures,
            ])
            ->all();
    }

    /**
     * Ausgehende Aufrufe.
     *
     * Beantwortet die Frage, die beim Webhook-Ausfall offenblieb: liegt es
     * an uns oder am fremden Dienst.
     */
    public function outgoing(int $hours, int $limit = 20): array
    {
        return $this->grouped(PerfEvent::TYPE_HTTP, $hours, $limit)
            ->map(fn ($row) => [
                'name'     => $row->name,
                'hits'     => (int) $row->hits,
                'avg_ms'   => (int) round((float) $row->avg_ms),
                'max_ms'   => (int) $row->max_ms,
                'errors'   => (int) $row->errors + (int) $row->failures,
            ])
            ->all();
    }

    /**
     * Der Zustand der Hintergrundarbeit.
     *
     * Nicht aggregiert, sondern fortgeschrieben — eine Zeile pro Kommando
     * mit der einzigen Frage, die zaehlt: wann lief es zuletzt.
     */
    public function commands(): array
    {
        return PerfCommandRun::orderByDesc('last_run_at')->get()
            ->map(fn (PerfCommandRun $run) => [
                'command'      => $run->command,
                'last_run_at'  => $run->last_run_at?->toIso8601String(),
                'ago_minutes'  => $run->last_run_at ? (int) $run->last_run_at->diffInMinutes(now()) : null,
                'duration_ms'  => $run->last_duration_ms,
                'query_count'  => $run->last_query_count,
                'exit_code'    => $run->last_exit_code,
                'slowest_ms'   => $run->slowest_ms,
                'runs_total'   => $run->runs_total,
                'failures'     => $run->failures_total,
                'overdue'      => $run->isOverdue(),
                'expected_min' => config('observability.expected_every_minutes')[$run->command] ?? null,
            ])
            ->all();
    }

    /** Die langsamsten Einzelanfragen — fuer den konkreten Fall. */
    public function slowest(int $hours, int $limit = 10): array
    {
        return PerfEvent::query()
            ->where('type', PerfEvent::TYPE_REQUEST)
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderByDesc('duration_ms')
            ->limit($limit)
            ->get()
            ->map(fn (PerfEvent $e) => [
                'name'        => $e->name,
                'path'        => $e->context['path'] ?? null,
                'duration_ms' => $e->duration_ms,
                'query_count' => $e->query_count,
                'query_ms'    => $e->query_ms,
                'memory_kb'   => $e->memory_kb,
                'status'      => $e->status,
                'at'          => $e->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Eine Zeile Gesamtbild ueber dem Rest. */
    public function summary(int $hours): array
    {
        $requests = PerfEvent::query()
            ->where('type', PerfEvent::TYPE_REQUEST)
            ->where('created_at', '>=', now()->subHours($hours))
            ->selectRaw('count(*) as hits, avg(duration_ms) as avg_ms, max(duration_ms) as max_ms, avg(query_count) as avg_queries')
            ->selectRaw("sum(case when status like '5%' then 1 else 0 end) as errors")
            ->first();

        return [
            'hours'       => $hours,
            'hits'        => (int) ($requests->hits ?? 0),
            'avg_ms'      => (int) round((float) ($requests->avg_ms ?? 0)),
            'max_ms'      => (int) ($requests->max_ms ?? 0),
            'avg_queries' => (int) round((float) ($requests->avg_queries ?? 0)),
            'errors'      => (int) ($requests->errors ?? 0),
            'recorded'    => (bool) config('observability.enabled', true),
            'retention'   => (int) config('observability.retention_days', 14),
        ];
    }

    /**
     * Die gemeinsame Aggregation aller Typen.
     *
     * `like '5%'` statt eines Zahlenvergleichs: `status` ist eine
     * Zeichenkette (dort steht auch „failed"), und ein CAST schreibt sich
     * unter MySQL anders als unter SQLite.
     */
    private function grouped(string $type, int $hours, int $limit): Collection
    {
        return PerfEvent::query()
            ->where('type', $type)
            ->where('created_at', '>=', now()->subHours($hours))
            ->groupBy('name')
            ->selectRaw('name, count(*) as hits, avg(duration_ms) as avg_ms, max(duration_ms) as max_ms')
            ->selectRaw('avg(query_count) as avg_queries, max(query_count) as max_queries')
            ->selectRaw("sum(case when status like '5%' then 1 else 0 end) as errors")
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failures")
            ->orderByDesc('hits')
            ->limit($limit)
            ->get();
    }
}
