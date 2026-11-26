<?php

namespace App\Services\Rouvy;

use App\Models\Activity;
use App\Models\IgnoredActivity;
use App\Models\User;
use App\Services\ActivityImportService;
use App\Services\Fit\FitActivityAttributes;
use App\Services\Fit\FitDecoder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * ROUVY-Fahrten zurück (18.11.2026).
 *
 * ROUVY meldet eine fertige Fahrt per Webhook (`type = activity`, die Fahrt
 * als Nutzlast); darunter liegt `rouvy:sync --activities` als Netz. Jede
 * ROUVY-Fahrt ist eine Indoor-Radfahrt (`VirtualRide`) und wird wie bei
 * intervals.icu **unabhängig von der gewählten Aktivitätsquelle** übernommen
 * — ROUVY ist keine wählbare Quelle. Lädt ROUVY dieselbe Fahrt auch zu
 * Garmin oder Strava hoch, hängt `ActivityImportService::store()` sie an.
 *
 * Kennung ist die Startzeit (`rouvy_start`), ROUVYs ID steht daneben.
 * Zahlen aus der FIT-Datei, ohne Datei aus der Zusammenfassung.
 */
class RouvyActivityImporter
{
    /** So viele Abrufe in Folge mit 5xx, bevor es als Fehler gemeldet wird (alle 2 h → 6 h). */
    public const SERVER_ERRORS_BEFORE_REPORT = 3;

    public const FRESH_HOURS = 48;

    public const SYNC_DAYS = 3;

    /** Nach dem Verbinden einmal so weit zurück, wie die anderen Quellen. */
    public const BACKFILL_DAYS = 42;

    public function __construct(
        private readonly RouvyClient $client,
        private readonly ActivityImportService $activities,
        private readonly FitDecoder $fit,
    ) {}

    public function reads(User $user): bool
    {
        return (bool) $user->rouvyConnection?->readsData();
    }

    /** Eine Fahrt aus dem Webhook. Die Nutzlast ist schon die ganze Fahrt. */
    public function importPayload(User $user, array $activity): string
    {
        if (! $this->reads($user)) {
            return 'no_consent';
        }

        $result = $this->import($user, $activity);
        if ($result === 'imported') {
            $this->activities->dispatchPlanRegenerationIfNeeded($user->id);
        }

        return $result;
    }

    /** Das Netz unter dem Webhook. */
    public function sync(User $user, ?int $days = null): ?array
    {
        if (! $this->reads($user)) {
            return null;
        }

        $connection = $user->rouvyConnection;
        $days     ??= $connection->activities_synced_at ? self::SYNC_DAYS : self::BACKFILL_DAYS;

        $failures = 'rouvy:activities-server-errors:' . $user->id;

        try {
            $list = $this->client->activities($connection, now()->subDays($days));
        } catch (RouvyException $e) {
            // Ein 5xx bei ROUVY ist meist nach dem nächsten Lauf vorbei: erst
            // nach mehreren Läufen in Folge ein Fehler (Sofort-Push), vorher
            // nur eine Warnung. Der Webhook bringt die Fahrt ohnehin.
            if ($e->serverSide()) {
                Cache::add($failures, 0, now()->addDay());
                $count = Cache::increment($failures);
                if ($count < self::SERVER_ERRORS_BEFORE_REPORT) {
                    Log::warning('ROUVY Aktivitäten: Serverfehler bei ROUVY, nächster Lauf versucht es wieder', [
                        'user_id' => $user->id, 'in_folge' => $count, 'message' => Str::limit($e->getMessage(), 200),
                    ]);

                    return null;
                }
            }
            $connection->update(['last_error' => RouvyWorkoutSync::errorText($e), 'last_error_at' => now()]);
            throw $e;
        }

        Cache::forget($failures);

        $outcomes = [];
        $fresh    = false;
        foreach ($list as $activity) {
            $result = $this->import($user, $activity);
            $outcomes[$result] = ($outcomes[$result] ?? 0) + 1;
            $fresh = $fresh || $result === 'imported';
        }

        if ($fresh) {
            $this->activities->dispatchPlanRegenerationIfNeeded($user->id);
        }

        $connection->update(['activities_synced_at' => now()]);

        return $outcomes;
    }

    private function import(User $user, array $activity): string
    {
        $id    = (string) ($activity['activityId'] ?? '');
        $start = self::startKey($activity);
        if ($id === '' || $start === null) {
            return 'skipped';
        }
        if (IgnoredActivity::blocks($user->id, Activity::SOURCE_ROUVY, $start)) {
            return 'tombstoned';
        }

        $existing = Activity::where('user_id', $user->id)->where('rouvy_start', $start)->first();
        if ($existing) {
            if (! $existing->rouvy_activity_id) {
                $existing->update(['rouvy_activity_id' => $id]);
            }

            return 'known';
        }

        $row = $this->activities->store($user->id, Activity::SOURCE_ROUVY, $start, $this->attributes($user, $activity));
        if (! $row->rouvy_activity_id) {
            $row->update(['rouvy_activity_id' => $id]);
        }

        if (! $row->wasRecentlyCreated) {
            return 'attached';
        }

        if ($start >= now()->subHours(self::FRESH_HOURS)->timestamp) {
            $this->activities->processNew($row);
        }

        Log::info('ROUVY: Fahrt importiert', ['user_id' => $user->id, 'activity_id' => $row->id]);

        return 'imported';
    }

    /** Was die Fahrt hergibt, in Zone3s Namen. */
    public function attributes(User $user, array $activity): array
    {
        $start    = self::startKey($activity);
        $moving   = (int) ($activity['movingTimeSeconds'] ?? 0);
        $elapsed  = (int) ($activity['elapsedTimeSeconds'] ?? $moving);
        $moving   = $moving ?: $elapsed;
        $distance = (float) ($activity['distanceMeters'] ?? 0);
        $agg      = (array) ($activity['aggregates'] ?? []);
        $hr       = (array) ($agg['heartRateBPM'] ?? []);
        $power    = (array) ($agg['powerWatts'] ?? []);
        $speed    = (array) ($agg['speedMetersPerSecond'] ?? []);
        $np       = $activity['normalizedPowerWatts'] ?? null;

        $attributes = array_filter([
            'name'                   => Str::limit((string) ($activity['activityTitle'] ?? '') ?: 'ROUVY-Fahrt', 250, ''),
            'type'                   => 'VirtualRide',
            'start_date'             => Carbon::createFromTimestampUTC($start),
            'distance'               => $distance,
            'moving_time'            => $moving,
            'elapsed_time'           => $elapsed,
            'average_speed'          => ($speed['avg'] ?? 0) > 0 ? $speed['avg'] : ($moving > 0 && $distance > 0 ? round($distance / $moving, 3) : 0),
            'max_speed'              => ($speed['max'] ?? 0) > 0 ? $speed['max'] : null,
            'total_elevation_gain'   => $activity['elevationGainMeters'] ?? null,
            'average_heartrate'      => ($hr['avg'] ?? 0) > 0 ? $hr['avg'] : null,
            'max_heartrate'          => ($hr['max'] ?? 0) > 0 ? $hr['max'] : null,
            'average_watts'          => ($power['avg'] ?? 0) > 0 ? $power['avg'] : null,
            'weighted_average_watts' => $np > 0 ? $np : null,
            'device_watts'           => ($power['avg'] ?? 0) > 0 ? true : null,
            'device_name'            => 'ROUVY',
        ], fn ($v) => $v !== null);

        try {
            $bytes = $this->client->activityFile($user->rouvyConnection, (string) $activity['activityId']);
            if ($bytes) {
                $decoded = $this->fit->decode($bytes);
                if (! empty($decoded['session'])) {
                    $attributes = array_merge($attributes, FitActivityAttributes::fromDecoded($decoded, Activity::SOURCE_ROUVY));
                    $attributes['type'] = 'VirtualRide';
                }
            }
        } catch (Throwable $e) {
            Log::info('ROUVY: FIT-Datei nicht lesbar, nehme die Zusammenfassung', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return $attributes;
    }

    /** Start in UTC-Sekunden. */
    public static function startKey(array $activity): ?int
    {
        $raw = $activity['startDateUTC'] ?? null;
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw, 'UTC')->utc()->timestamp;
        } catch (Throwable) {
            return null;
        }
    }
}
