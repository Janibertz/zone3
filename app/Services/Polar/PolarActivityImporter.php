<?php

namespace App\Services\Polar;

use App\Models\Activity;
use App\Models\IgnoredActivity;
use App\Models\User;
use App\Services\ActivityDeletionService;
use App\Services\ActivityImportService;
use App\Services\BestEffortService;
use App\Services\Fit\FitActivityAttributes;
use App\Services\Fit\FitDecoder;
use App\Services\Fit\FitSport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trainings von Polar — die sechste Quelle neben Strava, Garmin, COROS,
 * FIT-Datei und Apple Health.
 *
 * Zwei Wege, ein Import: der Webhook (`EXERCISE`, Sekunden nach dem Hochladen
 * in Polar Flow) und `polar:sync-activities` alle 30 Minuten als Netz.
 * Beide landen in `import()`; was schon da ist, wird übersprungen.
 *
 * Kennung ist die Startzeit (UTC), siehe Migration. Die FIT-Datei bringt
 * Linie, Runden, Bewegungszeit und Bestzeiten — dieselbe Übersetzung wie
 * COROS und der FIT-Upload (`FitActivityAttributes`).
 *
 * Gelesen wird nur, wenn Polar die gewählte Quelle ist; sonst stünde jeder
 * Lauf zweimal im Plan. Polar gibt nur Trainings ab der Verbindung heraus,
 * einen Rückblick gibt es nicht.
 */
class PolarActivityImporter
{
    public const MAX_NEW_PER_RUN = 10;

    public const FRESH_HOURS = 48;

    public function __construct(
        private readonly PolarClient $client,
        private readonly FitDecoder $fit,
        private readonly ActivityImportService $activities,
        private readonly BestEffortService $bestEfforts,
        private readonly ActivityDeletionService $deletion,
    ) {}

    public function reads(User $user): bool
    {
        return (bool) $user->polarConnection?->readsData() && $user->activitySource() === Activity::SOURCE_POLAR;
    }

    /**
     * Die Liste der letzten 30 Tage abgleichen.
     *
     * @return array<string, int>|null null = nicht abgefragt
     */
    public function sync(User $user): ?array
    {
        if (! $this->reads($user)) {
            return null;
        }

        $connection = $user->polarConnection;

        try {
            $list = $this->client->exercises($connection);
        } catch (PolarException $e) {
            $this->noteError($user, $e);
            throw $e;
        }

        $known = Activity::where('user_id', $user->id)->whereNotNull('polar_start')->pluck('polar_start')->map(fn ($v) => (int) $v)->all();

        $new = array_values(array_filter($list, function ($summary) use ($known, $user) {
            $start = self::startKey($summary);

            return $start !== null && ! in_array($start, $known, true)
                && ! IgnoredActivity::blocks($user->id, Activity::SOURCE_POLAR, $start);
        }));

        usort($new, fn ($a, $b) => self::startKey($b) <=> self::startKey($a));
        $batch = array_slice($new, 0, self::MAX_NEW_PER_RUN);

        $outcomes = ['imported' => 0, 'attached' => 0, 'pending' => count($new) - count($batch)];
        $fresh    = false;

        foreach ($batch as $summary) {
            $result = $this->importSummary($user, $summary);
            $outcomes[$result['result']]++;
            $fresh = $fresh || $result['fresh'];
        }

        if ($fresh) {
            $this->activities->dispatchPlanRegenerationIfNeeded($user->id);
        }

        $connection->update(['activities_synced_at' => now(), 'last_error' => null, 'last_error_at' => null]);

        return $outcomes;
    }

    /** Ein Training aus dem Webhook. */
    public function importById(User $user, string $exerciseId): string
    {
        if (! $this->reads($user)) {
            return 'other_source';
        }

        try {
            $summary = $this->client->exercise($user->polarConnection, $exerciseId);
        } catch (PolarException $e) {
            $this->noteError($user, $e);
            throw $e;
        }

        $start = self::startKey($summary);
        if ($start === null) {
            return 'invalid';
        }
        if (IgnoredActivity::blocks($user->id, Activity::SOURCE_POLAR, $start)) {
            return 'ignored';
        }
        if (Activity::where('user_id', $user->id)->where('polar_start', $start)->exists()) {
            return 'known';
        }

        $result = $this->importSummary($user, $summary);
        if ($result['fresh']) {
            $this->activities->dispatchPlanRegenerationIfNeeded($user->id);
        }

        return $result['result'];
    }

    /** @return array{result: string, fresh: bool} */
    private function importSummary(User $user, array $summary): array
    {
        $start      = self::startKey($summary);
        $attributes = $this->attributes($summary);
        $decoded    = $this->fitFor($user, (string) ($summary['id'] ?? ''));

        if ($decoded) {
            $session = $decoded['session'] ?? [];
            if (isset($session['sport'])) {
                $attributes['type'] = FitSport::toInternal($session['sport'], $session['sub_sport'] ?? null);
            }
            $attributes = array_merge($attributes, FitActivityAttributes::fromDecoded($decoded, Activity::SOURCE_POLAR));
        }

        $activity = $this->activities->store($user->id, Activity::SOURCE_POLAR, $start, $attributes);

        if (! empty($summary['device']) && $user->polarConnection->device_name !== $summary['device']) {
            $user->polarConnection->update(['device_name' => $summary['device']]);
        }

        if (! $activity->wasRecentlyCreated) {
            return ['result' => 'attached', 'fresh' => false];
        }

        $efforts = $decoded ? BestEffortService::effortsFromStream(FitActivityAttributes::stream($decoded['records'] ?? [])) : [];
        $fresh   = $start >= now()->subHours(self::FRESH_HOURS)->timestamp;

        if ($fresh) {
            $this->activities->processNew(
                $activity,
                $decoded ? fn (Activity $a) => $this->bestEfforts->syncFromActivityData($a, ['best_efforts' => $efforts]) : null,
            );
        } elseif ($decoded && $activity->type === 'Run') {
            $this->bestEfforts->syncFromActivityData($activity, ['best_efforts' => $efforts]);
        }

        Log::info('Polar-Training importiert', [
            'user_id'     => $user->id,
            'polar_start' => $start,
            'type'        => $activity->type,
            'fresh'       => $fresh,
            'fit'         => (bool) $decoded,
        ]);

        return ['result' => 'imported', 'fresh' => $fresh];
    }

    /** Was die Zusammenfassung hergibt, in Zone3s Namen. */
    public function attributes(array $summary): array
    {
        $duration = self::seconds($summary['duration'] ?? null);
        $distance = (float) ($summary['distance'] ?? 0);
        $type     = self::type($summary);
        $start    = self::startKey($summary);

        return array_filter([
            'name'              => FitSport::defaultName($type),
            'type'              => $type,
            'distance'          => $distance,
            'moving_time'       => $duration,
            'elapsed_time'      => $duration,
            'average_speed'     => $duration > 0 ? round($distance / $duration, 3) : 0,
            'average_heartrate' => $summary['heart_rate']['average'] ?? null,
            'max_heartrate'     => $summary['heart_rate']['maximum'] ?? null,
            // Wie bei Strava die UTC-Uhrzeit, sonst findet der Abgleich die Einheit nicht.
            'start_date'        => $start !== null ? Carbon::createFromTimestampUTC($start) : null,
            'device_name'       => $summary['device'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Startzeit in UTC-Sekunden. Polar schreibt die Ortszeit ohne Zone und
     * den Versatz in Minuten daneben.
     */
    public static function startKey(array $summary): ?int
    {
        if (empty($summary['start_time'])) {
            return null;
        }

        try {
            $local = Carbon::parse(substr((string) $summary['start_time'], 0, 19), 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $local->timestamp - ((int) ($summary['start_time_utc_offset'] ?? 0)) * 60;
    }

    /** ISO-8601-Dauer („PT1H2M3.5S“) in ganzen Sekunden. */
    public static function seconds(?string $iso): int
    {
        if (! $iso || ! preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:([\d.]+)S)?)?$/', $iso, $m)) {
            return 0;
        }

        return (int) round(((int) ($m[1] ?? 0)) * 86400 + ((int) ($m[2] ?? 0)) * 3600 + ((int) ($m[3] ?? 0)) * 60 + (float) ($m[4] ?? 0));
    }

    /** Sportart aus Polars Namen, solange die FIT-Datei fehlt. */
    public static function type(array $summary): string
    {
        $detail = strtoupper((string) ($summary['detailed_sport_info'] ?? ''));
        $sport  = strtoupper((string) ($summary['sport'] ?? ''));

        return match (true) {
            str_contains($detail, 'INDOOR_CYCLING'), str_contains($detail, 'VIRTUAL') && str_contains($detail, 'CYCL') => 'VirtualRide',
            str_contains($detail, 'RUNNING'), $sport === 'RUNNING'                                              => 'Run',
            str_contains($detail, 'BIK'), str_contains($detail, 'CYCLING'), $sport === 'CYCLING'                 => 'Ride',
            str_contains($detail, 'SWIM'), $sport === 'SWIMMING'                                                 => 'Swim',
            str_contains($detail, 'STRENGTH')                                                                    => 'WeightTraining',
            str_contains($detail, 'HIKING')                                                                      => 'Hike',
            str_contains($detail, 'WALKING')                                                                     => 'Walk',
            default                                                                                              => 'Workout',
        };
    }

    private function fitFor(User $user, string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        try {
            $bytes = $this->client->fit($user->polarConnection, $id);

            return $bytes ? $this->fit->decode($bytes) : null;
        } catch (Throwable $e) {
            Log::warning('Polar: FIT-Datei nicht geladen', ['user_id' => $user->id, 'exercise' => $id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function noteError(User $user, PolarException $e): void
    {
        if ($e->revoked()) {
            $user->polarConnection?->update(['last_error' => 'Polar nimmt die Verbindung nicht mehr an', 'last_error_at' => now()]);
        }
    }

    /** Beim Trennen: was Polar angelegt hat, geht (wie bei COROS). */
    public function forget(User $user): int
    {
        $count = 0;

        foreach (Activity::where('user_id', $user->id)->whereNotNull('polar_start')->get() as $activity) {
            if ($activity->source !== Activity::SOURCE_POLAR) {
                $activity->update(['polar_start' => null]);
                continue;
            }

            $this->deletion->unlink($activity);
            $activity->delete();
            $count++;
        }

        return $count;
    }
}
