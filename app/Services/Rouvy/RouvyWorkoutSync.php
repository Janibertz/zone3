<?php

namespace App\Services\Rouvy;

use App\Models\RouvyScheduledWorkout;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Bike\ZwoWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Die Radeinheiten der nächsten sieben Tage in die ROUVY-Bibliothek, jeweils
 * mit Datum (`plannedAt`, nur der Tag zählt).
 *
 * - Nur Einheiten mit Schritten in % FTP (`hasBikeSteps()`), als .zwo aus
 *   demselben `ZwoWriter` wie der Download und intervals.icu.
 * - **ROUVY kennt kein Ändern** (api@rouvy.com, 18.11.2026): eine geänderte
 *   Einheit wird gelöscht und neu gesendet. Verglichen wird lokal über
 *   `payload_hash`, ein unveränderter Plan kostet keinen Aufruf.
 * - Was aus dem Plan fällt, wird gelöscht; vergangene und erledigte bleiben.
 */
class RouvyWorkoutSync
{
    public const DAYS_AHEAD = 7;

    public function __construct(
        private readonly RouvyClient $client,
        private readonly ZwoWriter $zwo,
    ) {}

    /** @return array{written:int, removed:int, unchanged:int}|null null = keine Verbindung */
    public function sync(User $user): ?array
    {
        $connection = $user->rouvyConnection;
        if (! $connection) {
            return null;
        }

        $wanted   = $this->wanted($user);
        $existing = RouvyScheduledWorkout::where('user_id', $user->id)->get()->keyBy('training_session_id');
        $result   = ['written' => 0, 'removed' => 0, 'unchanged' => 0];

        try {
            foreach ($wanted as $sessionId => $w) {
                $row = $existing[$sessionId] ?? null;
                if ($row && $row->payload_hash === $w['hash']) {
                    $result['unchanged']++;
                    continue;
                }

                if ($row) {
                    $this->client->deleteWorkout($connection, $row->workout_id);
                }

                $workoutId = $this->client->pushWorkout($connection, $w['date'], $w['filename'], $w['zwo']);

                RouvyScheduledWorkout::updateOrCreate(
                    ['user_id' => $user->id, 'training_session_id' => $sessionId],
                    ['workout_id' => $workoutId, 'planned_date' => $w['date'], 'payload_hash' => $w['hash']],
                );
                $result['written']++;
            }

            foreach ($existing->reject(fn ($row, $sessionId) => $wanted->has($sessionId)) as $row) {
                $session = $row->training_session_id ? TrainingSession::find($row->training_session_id) : null;
                // Vergangen oder gefahren: bleibt in ROUVY stehen, nur unsere Zeile geht.
                $keep = $row->planned_date->lt(today()) || $session?->status === 'completed';
                if (! $keep) {
                    $this->client->deleteWorkout($connection, $row->workout_id);
                    $result['removed']++;
                }
                $row->delete();
            }

            $connection->update(['workouts_synced_at' => now(), 'last_error' => null, 'last_error_at' => null]);
        } catch (RouvyException $e) {
            $connection->update([
                'last_error'    => self::errorText($e),
                'last_error_at' => now(),
            ]);
            Log::warning('ROUVY-Workouts: Abgleich abgebrochen', ['user_id' => $user->id, 'error' => $e->getMessage()] + $result);

            return $result;
        }

        if ($result['written'] || $result['removed']) {
            Log::info('ROUVY-Workouts abgeglichen', ['user_id' => $user->id] + $result);
        }

        return $result;
    }

    /** Beim Trennen: was noch bevorsteht, aus ROUVY nehmen. */
    public function removeAll(User $user): void
    {
        $connection = $user->rouvyConnection;
        $upcoming   = RouvyScheduledWorkout::where('user_id', $user->id)
            ->whereDate('planned_date', '>=', today()->toDateString())
            ->pluck('workout_id');

        if ($connection) {
            foreach ($upcoming as $workoutId) {
                try {
                    $this->client->deleteWorkout($connection, $workoutId);
                } catch (Throwable $e) {
                    Log::warning('ROUVY-Workouts: beim Trennen nicht gelöscht', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                    break;
                }
            }
        }

        RouvyScheduledWorkout::where('user_id', $user->id)->delete();
    }

    /**
     * Was in ROUVY stehen soll, nach Einheit.
     *
     * @return Collection<int, array{date:string, filename:string, zwo:string, hash:string}>
     */
    public function wanted(User $user): Collection
    {
        $plan = $user->activeTrainingPlan;

        return TrainingSession::where('user_id', $user->id)
            ->where(function ($q) use ($plan) {
                $q->whereNull('training_plan_id');
                if ($plan) {
                    $q->orWhere('training_plan_id', $plan->id);
                }
            })
            ->where('status', 'planned')
            ->whereDate('planned_date', '>=', today()->toDateString())
            ->whereDate('planned_date', '<=', today()->addDays(self::DAYS_AHEAD - 1)->toDateString())
            ->orderBy('planned_date')
            ->get()
            ->filter(fn (TrainingSession $s) => $s->hasBikeSteps())
            ->mapWithKeys(fn (TrainingSession $s) => [$s->id => $this->payload($s)]);
    }

    /** @return array{date:string, filename:string, zwo:string, hash:string} */
    public function payload(TrainingSession $session): array
    {
        $date     = $session->planned_date->format('Y-m-d');
        $zwo      = $this->zwo->write($session->title, (string) $session->description, $session->steps);
        $filename = Str::slug('zone3-' . $date . '-' . str_replace('×', 'x', $session->title)) . '.zwo';

        return [
            'date'     => $date,
            'filename' => $filename,
            'zwo'      => $zwo,
            'hash'     => hash('sha256', $date . "\n" . $zwo),
        ];
    }

    public static function errorText(RouvyException $e): string
    {
        return match (true) {
            $e->revoked()            => 'ROUVY nimmt die Verbindung nicht mehr an',
            $e->getCode() === 409    => 'In ROUVY fehlt ein Benutzername. Bitte im ROUVY-Profil eintragen.',
            default                  => Str::limit($e->getMessage(), 250),
        };
    }
}
