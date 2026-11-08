<?php

namespace App\Services\Apple;

use App\Models\Activity;
use App\Models\IgnoredActivity;
use App\Models\User;
use App\Services\ActivityImportService;
use App\Services\BestEffortService;
use App\Services\Fit\FitSport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Trainings aus Apple Health als Aktivitäten (08.11.2026, Jan: „Watch-Läufe
 * + Erholung“). Die fünfte Quelle neben Strava, Garmin, COROS und Datei.
 *
 * Die iOS-App liest die Trainings samt Herzfrequenz und Strecke und
 * schickt sie zusammengefasst (`POST /health/apple/workouts`): Summen,
 * Runden aus den Rundenmarken der Watch und für Läufe eine Zeitreihe
 * [Sekunden, Meter], aus der Zone3 die Bestzeiten selbst rechnet.
 *
 * Was HealthKit hat, das Plugin aber nicht liefert: die GPS-Strecke und die
 * Laufdynamik. Eine Apple-Aktivität hat deshalb keine Karte.
 *
 * Angenommen wird nur, wenn Apple Health die gewählte Quelle ist — sonst
 * stünde ein Lauf, den die Garmin-Uhr auch an Apple Health gibt, zweimal da.
 * Wie bei COROS: was älter als `FRESH_HOURS` ist (Rückblick), wird nur
 * gespeichert, ohne Push, Review und Zuordnung zu längst vergangenen
 * Einheiten.
 */
class AppleWorkoutImporter
{
    public const FRESH_HOURS = 48;

    public function __construct(
        private readonly ActivityImportService $activities,
        private readonly BestEffortService $bestEfforts,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $workouts  wie `AppleHealthController::workouts()` sie prüft
     * @return array<string, int>|null je Ergebnis die Zahl; null = nicht angenommen
     */
    public function store(User $user, array $workouts): ?array
    {
        if (! $user->apple_health_consent_at || $user->activitySource() !== Activity::SOURCE_APPLE) {
            return null;
        }

        $outcomes = ['imported' => 0, 'known' => 0, 'attached' => 0, 'tombstoned' => 0];
        $freshNew = false;

        foreach ($workouts as $workout) {
            $result = $this->importOne($user, $workout);
            $outcomes[$result['result']]++;
            $freshNew = $freshNew || $result['fresh'];
        }

        if ($freshNew) {
            $this->activities->dispatchPlanRegenerationIfNeeded($user->id);
        }

        $user->forceFill(['apple_activities_synced_at' => now()])->save();

        return $outcomes;
    }

    /** @return array{result: string, fresh: bool} */
    private function importOne(User $user, array $workout): array
    {
        $start = Carbon::parse($workout['start'])->utc();
        $id    = $start->timestamp;

        if (IgnoredActivity::blocks($user->id, Activity::SOURCE_APPLE, $id)) {
            return ['result' => 'tombstoned', 'fresh' => false];
        }

        $known = Activity::where('user_id', $user->id)->where('apple_start', $id)->exists();

        $activity = $this->activities->store($user->id, Activity::SOURCE_APPLE, $id, $this->attributes($workout));

        if (! $activity->wasRecentlyCreated) {
            return ['result' => $known ? 'known' : 'attached', 'fresh' => false];
        }

        $efforts = $activity->type === 'Run'
            ? BestEffortService::effortsFromStream($this->stream($workout['stream'] ?? []))
            : [];
        $fresh = $start->greaterThanOrEqualTo(now()->subHours(self::FRESH_HOURS));

        if ($fresh) {
            $this->activities->processNew(
                $activity,
                fn (Activity $a) => $this->bestEfforts->syncFromActivityData($a, ['best_efforts' => $efforts]),
            );
        } elseif ($efforts !== []) {
            // Rückblick: Bestzeiten ja, aber kein „neue Bestzeit“-Jubel.
            $this->bestEfforts->syncFromActivityData($activity, ['best_efforts' => $efforts]);
        }

        Log::info('Apple-Health-Training importiert', [
            'user_id'     => $user->id,
            'activity_id' => $activity->id,
            'type'        => $activity->type,
            'fresh'       => $fresh,
        ]);

        return ['result' => 'imported', 'fresh' => $fresh];
    }

    /** Was die App schickt, in Zone3s Namen. */
    public function attributes(array $workout): array
    {
        $start    = Carbon::parse($workout['start'])->utc();
        $end      = Carbon::parse($workout['end'])->utc();
        $type     = AppleWorkoutType::toInternal($workout['type'] ?? null);
        $moving   = (int) round($workout['duration_s'] ?? 0);
        $elapsed  = max($moving, $end->timestamp - $start->timestamp);
        $distance = round((float) ($workout['distance_m'] ?? 0), 1);

        $attributes = [
            'name'              => FitSport::defaultName($type),
            'type'              => $type,
            // Wie bei Strava die UTC-Uhrzeit, sonst findet der Abgleich die Einheit nicht.
            'start_date'        => $start,
            'distance'          => $distance,
            'moving_time'       => $moving,
            'elapsed_time'      => $elapsed,
            'average_speed'     => $moving > 0 ? round($distance / $moving, 3) : 0,
            'average_heartrate' => $workout['avg_hr'] ?? null,
            'max_heartrate'     => $workout['max_hr'] ?? null,
            'device_name'       => $workout['source_name'] ?? null,
        ];

        $laps = [];
        foreach ($workout['laps'] ?? [] as $i => $lap) {
            $lapTime     = (int) round($lap['duration_s'] ?? 0);
            $lapDistance = round((float) ($lap['distance_m'] ?? 0), 1);
            $laps[] = [
                'index'             => $i + 1,
                'name'              => 'Runde ' . ($i + 1),
                'elapsed_time'      => $lapTime,
                'moving_time'       => $lapTime,
                'distance'          => $lapDistance,
                'average_speed'     => $lapTime > 0 && $lapDistance > 0 ? round($lapDistance / $lapTime, 3) : null,
                'max_speed'         => null,
                'average_heartrate' => $lap['avg_hr'] ?? null,
                'max_heartrate'     => $lap['max_hr'] ?? null,
                'pace_zone'         => null,
            ];
        }
        if (count($laps) > 1) {
            $attributes['laps'] = $laps;
        }

        return array_filter($attributes, fn ($v) => $v !== null);
    }

    /** @return list<array{0: float, 1: float}> zeitlich sortiert */
    private function stream(array $points): array
    {
        $points = array_values(array_filter($points, fn ($p) => is_array($p) && count($p) >= 2));
        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);

        return array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $points);
    }
}
