<?php

namespace App\Services\Garmin;

use App\Models\GarminScheduledWorkout;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Workout;
use App\Services\Watch\AppleWorkoutWriter;
use App\Services\WorkoutPaceResolver;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Der Plan auf der Garmin-Uhr — die naechsten Tage, automatisch
 * (Entscheidung von Jan, 28.10.2026).
 *
 * Zone3 ist die Wahrheit, der Garmin-Kalender folgt. Was gesendet wurde,
 * steht in `garmin_scheduled_workouts` mit einem Fingerabdruck. Ein Abgleich
 * vergleicht nur lokal; bei Garmin wird nur angefragt, was sich wirklich
 * geaendert hat. Darum darf er stuendlich laufen und nach jeder Aenderung an
 * einer Einheit — ein unveraenderter Plan kostet keinen einzigen Aufruf.
 *
 * Geaendert wird bei Garmin durch Loeschen und neu Anlegen, nicht per PUT:
 * ein PUT verlangt die `ownerId` und das ganze Workout, und eine verschobene
 * Einheit braucht ohnehin einen neuen Termin. Zwei Aufrufe mehr, ein Weg
 * weniger.
 *
 * Grenzen: 200 Aufrufe pro Athlet und Tag (Starter). Eine komplett neue
 * Woche kostet 14 (Workout + Termin je Einheit), eine komplett geaenderte 28.
 */
class GarminWorkoutSync
{
    /** Wie weit voraus der Kalender gepflegt wird, heute mitgezaehlt. */
    public const DAYS_AHEAD = 7;

    public function __construct(
        private readonly GarminClient $client,
        private readonly GarminWorkoutBuilder $builder,
    ) {}

    /**
     * Den Garmin-Kalender auf den Stand des Plans bringen. Ist der Abgleich
     * abgeschaltet, raeumt er weg, was er selbst angelegt hat.
     *
     * @return array{created:int, removed:int, kept:int}
     */
    public function sync(User $user): array
    {
        $result     = ['created' => 0, 'removed' => 0, 'kept' => 0];
        $connection = $user->garminConnection;

        if (! $user->sendsWorkoutsViaGarminApi()) {
            return $result;
        }

        $wanted = $user->syncsPlanToGarmin() ? $this->wanted($user) : collect();
        $rows   = $this->managedRows($user);

        try {
            foreach ($rows as $row) {
                $session = $row->trainingSession;

                // Was erledigt ist, bleibt stehen — der Lauf ist gelaufen.
                if ($session && $session->status === 'completed') {
                    $result['kept']++;
                    continue;
                }

                $payload = $session ? ($wanted[$session->id] ?? null) : null;
                if ($payload && $row->payload_hash === $payload['hash']) {
                    $wanted->forget($session->id);
                    $result['kept']++;
                    continue;
                }

                $this->remove($connection, $row);
                $result['removed']++;
            }

            foreach ($wanted as $sessionId => $payload) {
                $this->create($connection, $payload, [
                    'training_session_id' => $sessionId,
                    'manual'              => $payload['manual'],
                ]);
                $result['created']++;
            }
        } catch (RequestException $e) {
            // 429 (Kontingent) oder eine entzogene Freigabe: abbrechen, der
            // naechste Lauf macht weiter. Was bis hier geschah, ist gespeichert.
            Log::warning('Garmin-Kalender: Abgleich abgebrochen', [
                'user_id' => $user->id,
                'status'  => $e->response->status(),
                'result'  => $result,
            ]);
        }

        if ($result['created'] || $result['removed']) {
            Log::info('Garmin-Kalender abgeglichen', ['user_id' => $user->id] + $result);
        }

        return $result;
    }

    /**
     * Eine einzelne Einheit per Knopf senden — auch ausserhalb der
     * sieben Tage. Danach pflegt der Abgleich sie mit, bis sie gelaufen ist.
     * Steht sie unveraendert schon da, passiert nichts.
     */
    public function push(TrainingSession $session): void
    {
        $user       = $session->user;
        $connection = $user->garminConnection;
        $payload    = $this->payload($session, manual: true);

        $existing = GarminScheduledWorkout::where('user_id', $user->id)
            ->where('training_session_id', $session->id)
            ->get();

        foreach ($existing as $row) {
            if ($row->payload_hash === $payload['hash']) {
                $row->update(['manual' => true]);
                return;
            }
            $this->remove($connection, $row);
        }

        $this->create($connection, $payload, ['training_session_id' => $session->id, 'manual' => true]);
    }

    /** Ein Workout aus dem Builder an einem Tag in den Kalender legen. */
    public function pushWorkout(User $user, Workout $workout, string $date): void
    {
        $resolved = app(WorkoutPaceResolver::class)->resolve($workout, $user->runnerProfile);
        $body     = $this->builder->build($workout->name, $workout->description, $resolved['steps']);

        $this->create($user->garminConnection, [
            'body' => $body,
            'date' => $date,
            'hash' => sha1(json_encode($body) . '|' . $date),
        ], ['workout_id' => $workout->id, 'manual' => true]);
    }

    /**
     * Beim Trennen: was noch bevorsteht, aus dem Garmin-Kalender nehmen,
     * solange der Token noch gilt. Vergangenes bleibt dort als Verlauf.
     * Ein Fehler haelt das Trennen nicht auf.
     */
    public function removeAll(User $user): void
    {
        $connection = $user->garminConnection;

        if ($connection) {
            $upcoming = GarminScheduledWorkout::where('user_id', $user->id)
                ->whereDate('date', '>=', today()->toDateString())
                ->get();

            foreach ($upcoming as $row) {
                try {
                    $this->remove($connection, $row);
                } catch (Throwable $e) {
                    Log::warning('Garmin-Kalender: Workout beim Trennen nicht geloescht', [
                        'user_id' => $user->id, 'error' => $e->getMessage(),
                    ]);
                    break;
                }
            }
        }

        GarminScheduledWorkout::where('user_id', $user->id)->delete();
    }

    /**
     * Was im Garmin-Kalender stehen soll, nach Einheit.
     *
     * @return Collection<int, array{body: array, date: string, hash: string, manual: bool}>
     */
    private function wanted(User $user): Collection
    {
        $plan  = $user->activeTrainingPlan;
        $today = today()->toDateString();

        $window = $plan
            ? TrainingSession::where('user_id', $user->id)
                ->where('training_plan_id', $plan->id)
                ->where('status', 'planned')
                ->whereDate('planned_date', '>=', $today)
                ->whereDate('planned_date', '<=', today()->addDays(self::DAYS_AHEAD - 1)->toDateString())
                ->get()
            : collect();

        // Per Knopf gesendete Einheiten pflegt der Abgleich weiter, auch
        // wenn sie hinter dem Fenster liegen.
        $manualIds = GarminScheduledWorkout::where('user_id', $user->id)
            ->whereNull('workout_id')->where('manual', true)
            ->whereNotNull('training_session_id')
            ->pluck('training_session_id');

        $manual = TrainingSession::whereIn('id', $manualIds)
            ->where('status', 'planned')
            ->whereDate('planned_date', '>=', $today)
            ->get();

        $weekly    = $user->runnerProfile?->weekly_availability ?? [];
        $overrides = $plan?->availability_overrides ?? [];

        return $window
            // Den Vereinstermin gibt der Trainer vor Ort vor; ein erfundenes
            // Workout auf der Uhr wäre falsch (Entscheidung von Jan).
            ->reject(fn (TrainingSession $s) => self::isFixedAppointment($s, $weekly, $overrides))
            ->merge($manual)
            ->unique('id')
            // Nur Läufe: Rennen nicht, Radeinheiten gehen zu Zwift.
            ->filter(fn (TrainingSession $s) => AppleWorkoutWriter::supports($s))
            ->mapWithKeys(fn (TrainingSession $s) => [$s->id => $this->payload($s, manual: $manualIds->contains($s->id))]);
    }

    /**
     * Ist die Einheit der feste Wochentermin aus dem Wochenraster
     * (Laufclub)? Gespeichert wird das nicht — die Einheit traegt nur ihren
     * Typ. Darum dieselbe Regel wie im Geruest: Termin an diesem Wochentag,
     * keine Tagesausnahme, und die Einheit hat den Typ des Termins.
     */
    public static function isFixedAppointment(TrainingSession $session, array $weekly, array $overrides): bool
    {
        $date  = $session->planned_date;
        $fixed = $weekly[strtolower($date->englishDayOfWeek)]['fixed'] ?? null;

        if (! $fixed || isset($overrides[$date->toDateString()])) {
            return false;
        }

        return $session->type === ($fixed['type'] ?? 'interval');
    }

    /** Was der Abgleich verwaltet: Plan-Einheiten von heute an, keine Builder-Workouts. */
    private function managedRows(User $user): Collection
    {
        return GarminScheduledWorkout::with('trainingSession')
            ->where('user_id', $user->id)
            ->whereNull('workout_id')
            ->whereDate('date', '>=', today()->toDateString())
            ->get();
    }

    /** @return array{body: array, date: string, hash: string, manual: bool} */
    private function payload(TrainingSession $session, bool $manual = false): array
    {
        $body = $this->builder->forSession($session);
        $date = $session->planned_date->toDateString();

        return [
            'body'   => $body,
            'date'   => $date,
            'hash'   => sha1(json_encode($body) . '|' . $date),
            'manual' => $manual,
        ];
    }

    private function create($connection, array $payload, array $attributes): void
    {
        $workoutId = $this->client->createWorkout($connection, $payload['body']);

        // Die Zeile steht, bevor der Termin angefragt wird: scheitert der,
        // weiss der naechste Lauf trotzdem, welches Workout wegzuraeumen ist.
        $row = GarminScheduledWorkout::create($attributes + [
            'user_id'           => $connection->user_id,
            'date'              => $payload['date'],
            'garmin_workout_id' => $workoutId,
            'payload_hash'      => $payload['hash'],
        ]);

        $row->update(['garmin_schedule_id' => $this->client->scheduleWorkout($connection, $workoutId, $payload['date'])]);
    }

    private function remove($connection, GarminScheduledWorkout $row): void
    {
        if ($row->garmin_schedule_id) {
            $this->client->deleteSchedule($connection, $row->garmin_schedule_id);
        }
        $this->client->deleteWorkout($connection, $row->garmin_workout_id);

        $row->delete();
    }
}
