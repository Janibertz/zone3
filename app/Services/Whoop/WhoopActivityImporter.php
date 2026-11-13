<?php

namespace App\Services\Whoop;

use App\Models\Activity;
use App\Models\IgnoredActivity;
use App\Models\User;
use App\Services\ActivityDeletionService;
use App\Services\ActivityImportService;
use App\Services\Fit\FitSport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trainings von WHOOP — die siebte Quelle (13.11.2026).
 *
 * WHOOP liefert nur eine Zusammenfassung: Dauer, Strecke (wenn die App sie
 * kennt), Puls, Höhenmeter, Strain. **Keine Linie, keine Runden, keine
 * Messpunkte** — also auch keine Bestzeiten. Für Läufer ist WHOOP darum als
 * Aktivitätsquelle schwächer als jede Uhr; der Hauptgrund für WHOOP ist die
 * Erholung (`WhoopHealthImporter`).
 *
 * Kennung ist die Startzeit (UTC-Sekunden, `whoop_start`), weil WHOOPs ID
 * eine UUID ist und Grabsteine und `ActivityImportService::store()` mit
 * Zahlen rechnen. Die UUID steht daneben (`whoop_workout_id`), damit der
 * Webhook `workout.deleted` die Zeile findet.
 *
 * Gelesen wird nur, wenn WHOOP die gewählte Quelle ist.
 */
class WhoopActivityImporter
{
    public const FRESH_HOURS = 48;

    /** Nach dem Verbinden einmal so weit zurück. */
    public const BACKFILL_DAYS = 42;

    public const SYNC_DAYS = 3;

    public const MAX_NEW_PER_RUN = 25;

    public function __construct(
        private readonly WhoopClient $client,
        private readonly ActivityImportService $activities,
        private readonly ActivityDeletionService $deletion,
    ) {}

    public function reads(User $user): bool
    {
        return (bool) $user->whoopConnection?->readsData() && $user->activitySource() === Activity::SOURCE_WHOOP;
    }

    /** @return array<string, int>|null null = nicht abgefragt */
    public function sync(User $user): ?array
    {
        if (! $this->reads($user)) {
            return null;
        }

        $connection = $user->whoopConnection;
        $since      = $connection->activities_synced_at ? now()->subDays(self::SYNC_DAYS) : now()->subDays(self::BACKFILL_DAYS);

        try {
            $list = $this->client->workouts($connection, $since);
        } catch (WhoopException $e) {
            $this->noteError($user, $e);
            throw $e;
        }

        $outcomes = ['imported' => 0, 'attached' => 0, 'known' => 0, 'skipped' => 0];
        $fresh    = false;
        $new      = 0;

        foreach ($list as $workout) {
            if ($new >= self::MAX_NEW_PER_RUN) {
                break;
            }
            $result = $this->importWorkout($user, $workout);
            $outcomes[$result['result']] = ($outcomes[$result['result']] ?? 0) + 1;
            $fresh = $fresh || $result['fresh'];
            if (in_array($result['result'], ['imported', 'attached'], true)) {
                $new++;
            }
        }

        if ($fresh) {
            $this->activities->dispatchPlanRegenerationIfNeeded($user->id);
        }

        $connection->update(['activities_synced_at' => now(), 'last_error' => null, 'last_error_at' => null]);

        return $outcomes;
    }

    /** Ein Workout aus dem Webhook (`workout.updated`). */
    public function importById(User $user, string $workoutId): string
    {
        if (! $this->reads($user)) {
            return 'other_source';
        }

        try {
            $workout = $this->client->workout($user->whoopConnection, $workoutId);
        } catch (WhoopException $e) {
            $this->noteError($user, $e);
            throw $e;
        }

        if (! $workout) {
            return 'not_found';
        }

        $result = $this->importWorkout($user, $workout);
        if ($result['fresh']) {
            $this->activities->dispatchPlanRegenerationIfNeeded($user->id);
        }

        return $result['result'];
    }

    /** `workout.deleted`: in WHOOP gelöscht, also hier auch — mit Grabstein. */
    public function deleteById(User $user, string $workoutId): string
    {
        $activity = Activity::where('user_id', $user->id)->where('whoop_workout_id', $workoutId)->first();
        if (! $activity) {
            return 'unknown_activity';
        }

        if ($activity->source !== Activity::SOURCE_WHOOP) {
            // Eine andere Quelle hat die Zeile angelegt: nur WHOOPs Kennung geht.
            $activity->update(['whoop_start' => null, 'whoop_workout_id' => null]);

            return 'detached';
        }

        $this->deletion->delete($activity);

        return 'deleted';
    }

    /** @return array{result: string, fresh: bool} */
    private function importWorkout(User $user, array $workout): array
    {
        $start = self::startKey($workout);
        if ($start === null || ($workout['score_state'] ?? 'SCORED') === 'UNSCORABLE') {
            return ['result' => 'skipped', 'fresh' => false];
        }
        if (IgnoredActivity::blocks($user->id, Activity::SOURCE_WHOOP, $start)) {
            return ['result' => 'skipped', 'fresh' => false];
        }

        $existing = Activity::where('user_id', $user->id)->where('whoop_start', $start)->first();
        if ($existing) {
            // Die Zahlen der ersten Quelle bleiben; nur die UUID nachtragen.
            if (! $existing->whoop_workout_id && ! empty($workout['id'])) {
                $existing->update(['whoop_workout_id' => $workout['id']]);
            }

            return ['result' => 'known', 'fresh' => false];
        }

        $activity = $this->activities->store($user->id, Activity::SOURCE_WHOOP, $start, $this->attributes($workout));

        if (! $activity->whoop_workout_id && ! empty($workout['id'])) {
            $activity->update(['whoop_workout_id' => $workout['id']]);
        }

        if (! $activity->wasRecentlyCreated) {
            return ['result' => 'attached', 'fresh' => false];
        }

        $fresh = $start >= now()->subHours(self::FRESH_HOURS)->timestamp;
        if ($fresh) {
            $this->activities->processNew($activity);
        }

        Log::info('WHOOP-Training importiert', ['user_id' => $user->id, 'whoop_start' => $start, 'type' => $activity->type, 'fresh' => $fresh]);

        return ['result' => 'imported', 'fresh' => $fresh];
    }

    /** Was das Workout hergibt, in Zone3s Namen. */
    public function attributes(array $workout): array
    {
        $start    = self::startKey($workout);
        $end      = self::timestamp($workout['end'] ?? null);
        $duration = $start !== null && $end !== null ? max(0, $end - $start) : 0;
        $score    = (array) ($workout['score'] ?? []);
        $distance = (float) ($score['distance_meter'] ?? 0);
        $type     = self::type($workout);

        return array_filter([
            'name'                 => FitSport::defaultName($type),
            'type'                 => $type,
            'distance'             => $distance,
            'moving_time'          => $duration,
            'elapsed_time'         => $duration,
            'average_speed'        => $duration > 0 && $distance > 0 ? round($distance / $duration, 3) : 0,
            'average_heartrate'    => ($score['average_heart_rate'] ?? 0) > 0 ? $score['average_heart_rate'] : null,
            'max_heartrate'        => ($score['max_heart_rate'] ?? 0) > 0 ? $score['max_heart_rate'] : null,
            'total_elevation_gain' => isset($score['altitude_gain_meter']) ? round((float) $score['altitude_gain_meter'], 1) : null,
            // Wie bei Strava die UTC-Uhrzeit, sonst findet der Abgleich die Einheit nicht.
            'start_date'           => $start !== null ? Carbon::createFromTimestampUTC($start) : null,
            'device_name'          => 'WHOOP',
        ], fn ($v) => $v !== null);
    }

    /** Startzeit in UTC-Sekunden (WHOOP schreibt ISO 8601 mit Z). */
    public static function startKey(array $workout): ?int
    {
        return self::timestamp($workout['start'] ?? null);
    }

    private static function timestamp(?string $iso): ?int
    {
        if (! $iso) {
            return null;
        }

        try {
            return Carbon::parse($iso)->utc()->timestamp;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Sportart: `sport_name` ist ein freier Text (die ID ist veraltet, steht
     * aber oft noch dabei). Unbekanntes wird `Workout`, nie ein Lauf.
     */
    public static function type(array $workout): string
    {
        $name = strtolower(str_replace(['_', '-'], ' ', (string) ($workout['sport_name'] ?? '')));

        $byName = match (true) {
            $name === '' => null,
            str_contains($name, 'spin'), str_contains($name, 'indoor cycl'), str_contains($name, 'zwift') => 'VirtualRide',
            str_contains($name, 'treadmill'), str_contains($name, 'running'), $name === 'run', str_contains($name, 'trail run') => 'Run',
            str_contains($name, 'mountain bik'), str_contains($name, 'cycling'), str_contains($name, 'gravel') => 'Ride',
            str_contains($name, 'swim') => 'Swim',
            str_contains($name, 'weightlifting'), str_contains($name, 'strength'), str_contains($name, 'powerlifting') => 'WeightTraining',
            str_contains($name, 'hik'), str_contains($name, 'ruck') => 'Hike',
            str_contains($name, 'walk') => 'Walk',
            str_contains($name, 'yoga') => 'Yoga',
            str_contains($name, 'pilates') => 'Pilates',
            default => null,
        };

        if ($byName) {
            return $byName;
        }

        return match ((int) ($workout['sport_id'] ?? -1)) {
            0       => 'Run',
            1, 57   => 'Ride',
            97      => 'VirtualRide',
            33      => 'Swim',
            45, 123 => 'WeightTraining',
            52      => 'Hike',
            63      => 'Walk',
            44      => 'Yoga',
            43      => 'Pilates',
            default => 'Workout',
        };
    }

    private function noteError(User $user, WhoopException $e): void
    {
        if ($e->revoked()) {
            $user->whoopConnection?->update(['last_error' => 'WHOOP nimmt die Verbindung nicht mehr an', 'last_error_at' => now()]);
        }
    }

    /** Beim Trennen: was WHOOP angelegt hat, geht (wie bei COROS und Polar). */
    public function forget(User $user): int
    {
        $count = 0;

        foreach (Activity::where('user_id', $user->id)->whereNotNull('whoop_start')->get() as $activity) {
            if ($activity->source !== Activity::SOURCE_WHOOP) {
                $activity->update(['whoop_start' => null, 'whoop_workout_id' => null]);
                continue;
            }

            $this->deletion->unlink($activity);
            $activity->delete();
            $count++;
        }

        return $count;
    }
}
