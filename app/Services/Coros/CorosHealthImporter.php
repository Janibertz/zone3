<?php

namespace App\Services\Coros;

use App\Models\GarminDailyMetric;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Erholungswerte von COROS in `garmin_daily_metrics` (Schlaf, HRV,
 * Ruhepuls, Stress, Schritte, VO2max).
 *
 * MCP schickt nichts von sich aus — `coros:sync-health` fragt stuendlich
 * nach. Abgefragt werden nur die Werkzeuge, die Zone3 braucht; Zyklusdaten
 * (`queryMenstruationCycles`) nie (Datenminimierung, Bedingung von COROS).
 *
 * Eine Quelle je Athlet: kommen schon Erholungswerte von Garmin, bleibt
 * COROS draussen — zwei Uhren messen eine Nacht nie gleich.
 */
class CorosHealthImporter
{
    /** Tage, die jeder Abruf auffrischt. Der Schlaf eines Tages kommt erst nach dem Aufwachen. */
    public const DAYS = 3;

    /** Beim ersten Abruf nach der Einwilligung. */
    public const BACKFILL_DAYS = 30;

    public const SOURCE = 'coros';

    public function __construct(
        private readonly CorosClient $client,
        private readonly CorosHealthParser $parser,
    ) {}

    /**
     * @return array<string, int>|null geschriebene Tage je Spalte; null = nicht abgefragt
     */
    public function sync(User $user, ?int $days = null): ?array
    {
        $connection = $user->corosConnection;

        if (! $connection?->readsData() || $user->garminConnection?->allows(\App\Models\GarminConnection::HEALTH)) {
            return null;
        }

        $days ??= $connection->health_synced_at ? self::DAYS : self::BACKFILL_DAYS;
        $start = now()->subDays($days - 1)->format('Ymd');
        $end   = now()->format('Ymd');

        $byDay = [];
        $merge = function (array $parsed) use (&$byDay) {
            foreach ($parsed as $date => $values) {
                $byDay[$date] = ($byDay[$date] ?? []) + $values;
            }
        };

        // Jedes Werkzeug fuer sich: faellt eins aus, kommen die anderen trotzdem.
        $tools = [
            ['queryDailyHealthData', ['days' => $days], fn ($t) => $this->parser->daily($t)],
            ['querySleepOverview', ['startDate' => $start, 'endDate' => $end], fn ($t) => $this->parser->sleep($t)],
            ['querySleepHrv', ['startDate' => $start, 'endDate' => $end], fn ($t) => $this->parser->hrv($t)],
            ['queryRestingHeartRate', ['days' => $days], fn ($t) => $this->parser->restingHr($t)],
            ['queryFitnessAssessmentOverview', [], fn ($t) => ($v = $this->parser->vo2max($t)) ? [now()->toDateString() => ['vo2max' => $v]] : []],
        ];

        $failed = 0;
        foreach ($tools as [$tool, $args, $parse]) {
            try {
                $merge($parse($this->client->callTool($connection, $tool, $args)));
            } catch (Throwable $e) {
                $failed++;
                Log::warning('COROS-Erholungswerte: Abruf fehlgeschlagen', ['user_id' => $user->id, 'tool' => $tool, 'error' => $e->getMessage()]);
            }
        }

        if ($failed === count($tools)) {
            return null;
        }

        $written = [];
        foreach ($byDay as $date => $values) {
            // whereDate, nicht where: SQLite speichert das Datum mit Uhrzeit.
            $metric = GarminDailyMetric::where('user_id', $user->id)->whereDate('date', $date)->first()
                ?? new GarminDailyMetric(['user_id' => $user->id, 'date' => $date]);

            $metric->fill($values + ['source' => self::SOURCE])->save();

            foreach (array_keys($values) as $column) {
                $written[$column] = ($written[$column] ?? 0) + 1;
            }
        }

        $connection->update(['health_synced_at' => now()]);

        return $written;
    }

    /** Beim Trennen oder Widerruf: nur was von COROS kam. */
    public function forget(User $user): void
    {
        GarminDailyMetric::where('user_id', $user->id)->where('source', self::SOURCE)->delete();
    }
}
