<?php

namespace App\Services\Intervals;

use App\Models\Activity;
use App\Models\IgnoredActivity;
use App\Models\User;
use App\Services\ActivityDeletionService;
use App\Services\ActivityImportService;
use App\Services\Fit\FitActivityAttributes;
use App\Services\Fit\FitDecoder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Zwift-Fahrten von intervals.icu zurück (15.11.2026).
 *
 * intervals.icu bekommt die Fahrt von Zwift (Training API) und meldet sie per
 * Webhook. Zone3 übernimmt **nur Zwift- bzw. Indoor-Radfahrten** — alles
 * andere kommt über die gewählte Quelle (Garmin, COROS …), sonst stünde jeder
 * Lauf doppelt da (Jan, 15.11.2026). Das gilt wie bei Strava unabhängig von
 * der gewählten Aktivitätsquelle.
 *
 * Die Zahlen kommen aus der Originaldatei (FIT, derselbe Leser wie COROS und
 * der Upload), ohne Datei aus der Zusammenfassung. Kennung ist die Startzeit
 * (`intervals_start`), die ID daneben (`intervals_activity_id`). Lädt Zwift
 * dieselbe Fahrt auch zu Strava hoch, hängt `ActivityImportService::store()`
 * sie an die bestehende Zeile.
 */
class IntervalsActivityImporter
{
    public const FRESH_HOURS = 48;

    public const SYNC_DAYS = 3;

    public function __construct(
        private readonly IntervalsClient $client,
        private readonly ActivityImportService $activities,
        private readonly ActivityDeletionService $deletion,
        private readonly FitDecoder $fit,
    ) {}

    public function reads(User $user): bool
    {
        return (bool) $user->intervalsConnection?->readsData();
    }

    /** Eine Fahrt aus dem Webhook. `$summary` = was der Webhook schon mitbringt. */
    public function importById(User $user, string $id, ?array $summary = null): string
    {
        if (! $this->reads($user)) {
            return 'no_consent';
        }

        $connection = $user->intervalsConnection;

        try {
            $activity = $this->client->activity($connection, $id) ?? $summary;
        } catch (IntervalsException $e) {
            $this->noteError($user, $e);
            throw $e;
        }

        if (! $activity) {
            return 'not_found';
        }

        $result = $this->import($user, $activity);
        if ($result === 'imported') {
            $this->activities->dispatchPlanRegenerationIfNeeded($user->id);
        }

        return $result;
    }

    /** Das Netz unter dem Webhook: die letzten Tage abfragen. */
    public function sync(User $user): ?array
    {
        if (! $this->reads($user)) {
            return null;
        }

        $connection = $user->intervalsConnection;

        try {
            $list = $this->client->activities($connection, now()->subDays(self::SYNC_DAYS), now()->addDay());
        } catch (IntervalsException $e) {
            $this->noteError($user, $e);
            throw $e;
        }

        $outcomes = [];
        $fresh    = false;
        foreach ($list as $activity) {
            if (! self::isZwift($activity)) {
                $outcomes['other'] = ($outcomes['other'] ?? 0) + 1;
                continue;
            }
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

    /** `ACTIVITY_DELETED`: in intervals.icu gelöscht, also hier auch — mit Grabstein. */
    public function deleteById(User $user, string $id): string
    {
        $activity = Activity::where('user_id', $user->id)->where('intervals_activity_id', $id)->first();
        if (! $activity) {
            return 'unknown_activity';
        }

        if ($activity->source !== Activity::SOURCE_INTERVALS) {
            // Eine andere Quelle hat die Zeile angelegt: nur intervals' Kennung geht.
            $activity->update(['intervals_start' => null, 'intervals_activity_id' => null]);

            return 'detached';
        }

        $this->deletion->delete($activity);

        return 'deleted';
    }

    /**
     * Zwift oder wenigstens eine Indoor-Radfahrt. intervals.icu nennt die
     * Herkunft nicht einheitlich, darum mehrere Merkmale. Was von Strava kam,
     * liefert die API ohnehin nicht.
     */
    public static function isZwift(array $activity): bool
    {
        if (strtoupper((string) ($activity['source'] ?? '')) === 'STRAVA') {
            return false;
        }

        $marks = strtolower(implode(' ', [
            $activity['source'] ?? '', $activity['device_name'] ?? '', $activity['external_id'] ?? '', $activity['name'] ?? '',
        ]));

        return ($activity['type'] ?? null) === 'VirtualRide' || str_contains($marks, 'zwift');
    }

    private function import(User $user, array $activity): string
    {
        if (! self::isZwift($activity)) {
            return 'not_zwift';
        }

        $id    = (string) ($activity['id'] ?? '');
        $start = self::startKey($activity);
        if ($id === '' || $start === null) {
            return 'skipped';
        }
        if (IgnoredActivity::blocks($user->id, Activity::SOURCE_INTERVALS, $start)) {
            return 'tombstoned';
        }

        $existing = Activity::where('user_id', $user->id)->where('intervals_start', $start)->first();
        if ($existing) {
            if (! $existing->intervals_activity_id) {
                $existing->update(['intervals_activity_id' => $id]);
            }

            return 'known';
        }

        $attributes = $this->attributes($user, $activity);

        $row = $this->activities->store($user->id, Activity::SOURCE_INTERVALS, $start, $attributes);
        if (! $row->intervals_activity_id) {
            $row->update(['intervals_activity_id' => $id]);
        }

        if (! $row->wasRecentlyCreated) {
            return 'attached';
        }

        if ($start >= now()->subHours(self::FRESH_HOURS)->timestamp) {
            $this->activities->processNew($row);
        }

        Log::info('intervals.icu: Zwift-Fahrt importiert', ['user_id' => $user->id, 'activity_id' => $row->id]);

        return 'imported';
    }

    /** Was die Fahrt hergibt, in Zone3s Namen. */
    public function attributes(User $user, array $activity): array
    {
        $start    = self::startKey($activity);
        $moving   = (int) ($activity['moving_time'] ?? 0);
        $elapsed  = (int) ($activity['elapsed_time'] ?? $moving);
        $distance = (float) ($activity['distance'] ?? 0);
        $avgWatts = $activity['icu_average_watts'] ?? $activity['average_watts'] ?? null;
        $npWatts  = $activity['icu_weighted_avg_watts'] ?? $activity['weighted_average_watts'] ?? null;

        $attributes = array_filter([
            'name'                   => Str::limit((string) ($activity['name'] ?? 'Zwift-Fahrt'), 250, ''),
            'type'                   => 'VirtualRide',
            'start_date'             => Carbon::createFromTimestampUTC($start),
            'distance'               => $distance,
            'moving_time'            => $moving,
            'elapsed_time'           => $elapsed,
            'average_speed'          => $moving > 0 && $distance > 0 ? round($distance / $moving, 3) : 0,
            'total_elevation_gain'   => $activity['total_elevation_gain'] ?? null,
            'average_heartrate'      => ($activity['average_heartrate'] ?? 0) > 0 ? $activity['average_heartrate'] : null,
            'max_heartrate'          => ($activity['max_heartrate'] ?? 0) > 0 ? $activity['max_heartrate'] : null,
            'average_watts'          => $avgWatts > 0 ? $avgWatts : null,
            'weighted_average_watts' => $npWatts > 0 ? $npWatts : null,
            'device_watts'           => $avgWatts > 0 ? true : null,
            'device_name'            => $activity['device_name'] ?? 'Zwift',
        ], fn ($v) => $v !== null);

        // Die Originaldatei bringt Runden, Watt und Linie — wenn es sie gibt.
        try {
            $bytes = $this->client->activityFile($user->intervalsConnection, (string) $activity['id']);
            if ($bytes) {
                $decoded = $this->fit->decode($bytes);
                if (! empty($decoded['session'])) {
                    $attributes = array_merge($attributes, FitActivityAttributes::fromDecoded($decoded, Activity::SOURCE_INTERVALS));
                    $attributes['type'] = 'VirtualRide';
                }
            }
        } catch (Throwable $e) {
            Log::info('intervals.icu: Originaldatei nicht lesbar, nehme die Zusammenfassung', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return $attributes;
    }

    /** Start in UTC-Sekunden. `start_date` ist UTC, `start_date_local` nicht. */
    public static function startKey(array $activity): ?int
    {
        $raw = $activity['start_date'] ?? null;
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw, 'UTC')->utc()->timestamp;
        } catch (Throwable) {
            return null;
        }
    }

    private function noteError(User $user, IntervalsException $e): void
    {
        $user->intervalsConnection?->update([
            'last_error'    => $e->revoked() ? 'intervals.icu nimmt die Verbindung nicht mehr an' : Str::limit($e->getMessage(), 250),
            'last_error_at' => now(),
        ]);
    }
}
