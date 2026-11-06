<?php

namespace App\Services\Coros;

use App\Models\Activity;
use App\Models\IgnoredActivity;
use App\Models\User;
use App\Services\ActivityDeletionService;
use App\Services\ActivityImportService;
use App\Services\BestEffortService;
use App\Services\Fit\FitDecoder;
use App\Services\Polyline;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aktivitaeten von COROS (MCP) — die dritte Quelle neben Strava und Garmin.
 *
 * MCP schickt nichts von sich aus: `coros:sync-activities` fragt alle
 * 15 Minuten die letzten Tage ab (`querySportRecords`), und fuer jede neue
 * Aktivitaet holt es die FIT-Datei (Linie, Runden, Bewegungszeit,
 * Bestzeiten). COROS erlaubt 50 FIT-Dateien pro Tag; ein Abruf holt
 * hoechstens `MAX_NEW_PER_RUN`, der naechste macht weiter.
 *
 * Gelesen wird nur mit Einwilligung, und nur wenn COROS die gewaehlte
 * Quelle des Athleten ist — sonst stuende jeder Lauf zweimal im Plan.
 *
 * Beim ersten Abruf nach der Einwilligung kommen `BACKFILL_DAYS` zurueck
 * (Schwelle, Umfang und VDOT brauchen sechs Wochen). Was dabei aelter als
 * `FRESH_HOURS` ist, wird nur gespeichert: kein Push, kein Review, keine
 * Zuordnung zu laengst vergangenen Einheiten.
 */
class CorosActivityImporter
{
    public const DAYS = 3;

    public const BACKFILL_DAYS = 42;

    public const MAX_NEW_PER_RUN = 10;

    public const FRESH_HOURS = 48;

    private const MAX_POLYLINE_POINTS = 2000;

    public function __construct(
        private readonly CorosClient $client,
        private readonly CorosActivityParser $parser,
        private readonly FitDecoder $fit,
        private readonly ActivityImportService $activities,
        private readonly BestEffortService $bestEfforts,
        private readonly ActivityDeletionService $deletion,
    ) {}

    /**
     * @return array<string, int>|null je Ergebnis die Zahl; null = nicht abgefragt
     */
    public function sync(User $user): ?array
    {
        $connection = $user->corosConnection;

        if (! $connection?->readsData() || $user->activitySource() !== Activity::SOURCE_COROS) {
            return null;
        }

        $backfill = $connection->activities_synced_at === null;
        $days     = $backfill ? self::BACKFILL_DAYS : self::DAYS;

        $text = $this->client->callTool($connection, 'querySportRecords', [
            'startDate' => now()->subDays($days - 1)->format('Ymd'),
            'endDate'   => now()->format('Ymd'),
        ]);

        $records = $this->parser->records($text);

        $known = Activity::where('user_id', $user->id)
            ->whereIn('coros_label_id', array_column($records, 'label_id'))
            ->pluck('coros_label_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $new = array_values(array_filter($records, fn ($r) => ! in_array($r['label_id'], $known, true)
            && ! IgnoredActivity::blocks($user->id, Activity::SOURCE_COROS, $r['label_id'])));

        // Neueste zuerst: ist das Kontingent erschoepft, fehlt eher Altes.
        usort($new, fn ($a, $b) => ($b['start'] ?? 0) <=> ($a['start'] ?? 0));
        $batch = array_slice($new, 0, self::MAX_NEW_PER_RUN);

        $outcomes = ['imported' => 0, 'attached' => 0, 'without_fit' => 0, 'pending' => count($new) - count($batch)];

        if ($batch !== [] && ! $connection->device_name) {
            try {
                $connection->update(['device_name' => $this->parser->deviceName($this->client->callTool($connection, 'queryDevices'))]);
            } catch (Throwable) {
                // Die Uhr ist Beiwerk; ohne Namen geht es auch.
            }
        }

        $freshNew = false;
        foreach ($batch as $record) {
            $outcome = $this->importOne($user, $record, $connection->device_name);
            $outcomes[$outcome['result']]++;
            if (! $outcome['fit']) {
                $outcomes['without_fit']++;
            }
            $freshNew = $freshNew || $outcome['fresh'];
        }

        if ($freshNew) {
            $this->activities->dispatchPlanRegenerationIfNeeded($user->id);
        }

        // Erst wenn alles da ist, gilt der Rueckblick als erledigt.
        if ($outcomes['pending'] === 0) {
            $connection->update(['activities_synced_at' => now()]);
        }

        return $outcomes;
    }

    /**
     * @return array{result: string, fit: bool, fresh: bool}
     */
    private function importOne(User $user, array $record, ?string $device): array
    {
        $attributes = $this->attributes($record, $device);
        $decoded    = $this->fitFor($user, $record);

        if ($decoded) {
            $attributes = array_merge($attributes, $this->fromFit($decoded));
        }

        $activity = $this->activities->store($user->id, Activity::SOURCE_COROS, $record['label_id'], $attributes);

        if (! $activity->wasRecentlyCreated) {
            return ['result' => 'attached', 'fit' => (bool) $decoded, 'fresh' => false];
        }

        $efforts = $decoded ? BestEffortService::effortsFromStream($this->stream($decoded['records'])) : [];
        $fresh   = $record['start'] !== null && $record['start'] >= now()->subHours(self::FRESH_HOURS)->timestamp;

        if ($fresh) {
            $this->activities->processNew(
                $activity,
                $decoded ? fn (Activity $a) => $this->bestEfforts->syncFromActivityData($a, ['best_efforts' => $efforts]) : null,
            );
        } elseif ($decoded && $activity->type === 'Run') {
            // Rueckblick: Bestzeiten ja, aber kein „neue Bestzeit“-Jubel.
            $this->bestEfforts->syncFromActivityData($activity, ['best_efforts' => $efforts]);
        }

        Log::info('COROS-Aktivitaet importiert', [
            'user_id'        => $user->id,
            'coros_label_id' => $record['label_id'],
            'type'           => $activity->type,
            'fresh'          => $fresh,
            'fit'            => (bool) $decoded,
        ]);

        return ['result' => 'imported', 'fit' => (bool) $decoded, 'fresh' => $fresh];
    }

    /** Was die Liste hergibt, in Zone3s Namen. */
    public function attributes(array $record, ?string $device): array
    {
        $duration = (int) ($record['duration'] ?? 0);
        $distance = (float) ($record['distance'] ?? 0);
        $elapsed  = $record['start'] && $record['end'] ? max($duration, $record['end'] - $record['start']) : $duration;

        return array_filter([
            'name'              => $record['name'] ?: $record['title'] ?: 'Aktivität',
            'type'              => CorosActivityType::toInternal($record['sport_type']),
            'distance'          => $distance,
            'moving_time'       => $duration,
            'elapsed_time'      => $elapsed,
            'average_speed'     => $duration > 0 ? round($distance / $duration, 3) : 0,
            'average_heartrate' => $record['avg_hr'],
            // Wie bei Strava die UTC-Uhrzeit, sonst findet der Abgleich die Einheit nicht.
            'start_date'        => $record['start'] ? Carbon::createFromTimestampUTC($record['start']) : null,
            'start_lat'         => $record['lat'] ?: null,
            'start_lng'         => $record['lng'] ?: null,
            'device_name'       => $device,
        ], fn ($v) => $v !== null);
    }

    /** Die FIT-Datei einer Aktivitaet, entschluesselt; null, wenn sie nicht kommt. */
    private function fitFor(User $user, array $record): ?array
    {
        try {
            $text = $this->client->callTool($user->corosConnection, 'queryActivityFitFileDownloadUrls', [
                'labelId'   => (string) $record['label_id'],
                'sportType' => $record['sport_type'],
            ]);

            if (! preg_match('#https://\S+\.fit#i', $text, $url)) {
                return null;
            }

            return $this->fit->decode(Http::timeout(60)->get($url[0])->throw()->body());
        } catch (Throwable $e) {
            Log::warning('COROS: FIT-Datei nicht geladen', ['user_id' => $user->id, 'coros_label_id' => $record['label_id'], 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Linie, Runden und die genauen Summen aus der FIT-Datei. */
    public function fromFit(array $decoded): array
    {
        $attributes = [];
        $session    = $decoded['session'];

        $map = [
            'total_timer_time'   => 'moving_time',
            'total_elapsed_time' => 'elapsed_time',
            'total_distance'     => 'distance',
            'total_ascent'       => 'total_elevation_gain',
            'avg_speed'          => 'average_speed',
            'max_speed'          => 'max_speed',
            'avg_heart_rate'     => 'average_heartrate',
            'max_heart_rate'     => 'max_heartrate',
            'avg_power'          => 'average_watts',
            'normalized_power'   => 'weighted_average_watts',
        ];
        foreach ($map as $from => $to) {
            if (isset($session[$from]) && $session[$from] > 0) {
                $attributes[$to] = in_array($to, ['moving_time', 'elapsed_time'], true) ? (int) round($session[$from]) : $session[$from];
            }
        }
        if (isset($attributes['average_watts'])) {
            $attributes['device_watts'] = true;
        }

        $points = [];
        foreach ($decoded['records'] as $r) {
            if (isset($r['lat'], $r['lng'])) {
                $points[] = [round($r['lat'], 6), round($r['lng'], 6)];
            }
        }
        if (count($points) >= 2) {
            $step = (int) ceil(count($points) / self::MAX_POLYLINE_POINTS);
            $kept = $step > 1 ? array_values(array_filter($points, fn ($i) => $i % $step === 0, ARRAY_FILTER_USE_KEY)) : $points;
            if (end($kept) !== end($points)) {
                $kept[] = end($points);
            }
            $attributes['polyline'] = ['polyline' => Polyline::encode($kept)];
        }

        $laps = [];
        foreach ($decoded['laps'] as $i => $lap) {
            $moving   = (int) round($lap['total_timer_time'] ?? $lap['total_elapsed_time'] ?? 0);
            $distance = round((float) ($lap['total_distance'] ?? 0), 1);
            $laps[]   = [
                'index'             => $i + 1,
                'name'              => 'Runde ' . ($i + 1),
                'elapsed_time'      => (int) round($lap['total_elapsed_time'] ?? $moving),
                'moving_time'       => $moving,
                'distance'          => $distance,
                'average_speed'     => $lap['avg_speed'] ?? ($moving > 0 ? round($distance / $moving, 3) : null),
                'max_speed'         => $lap['max_speed'] ?? null,
                'average_heartrate' => $lap['avg_heart_rate'] ?? null,
                'max_heartrate'     => $lap['max_heart_rate'] ?? null,
                'pace_zone'         => null,
            ];
        }
        if ($laps !== []) {
            $attributes['laps'] = $laps;
        }

        return $attributes;
    }

    /** [Sekunden, Meter gesamt] fuer die Bestzeiten. */
    private function stream(array $records): array
    {
        $points = [];
        foreach ($records as $r) {
            if (isset($r['timestamp'], $r['distance'])) {
                $points[] = [(int) $r['timestamp'], (float) $r['distance']];
            }
        }
        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);

        return $points;
    }

    /** Beim Trennen: was COROS angelegt hat, geht (Entscheidung von Jan, 06.11.2026). */
    public function forget(User $user): int
    {
        $count = 0;

        foreach (Activity::where('user_id', $user->id)->whereNotNull('coros_label_id')->get() as $activity) {
            if ($activity->source !== Activity::SOURCE_COROS) {
                // Aus einer anderen Quelle angelegt, COROS nur angehaengt: nur die ID geht.
                $activity->update(['coros_label_id' => null]);
                continue;
            }

            $this->deletion->unlink($activity);
            $activity->delete();
            $count++;
        }

        return $count;
    }
}
