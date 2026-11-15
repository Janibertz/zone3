<?php

namespace App\Services\Intervals;

use App\Models\IntervalsScheduledWorkout;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Bike\ZwoWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Die Radeinheiten der nächsten sieben Tage in den intervals-Kalender —
 * intervals.icu reicht sie an Zwift weiter (Zwift Training API, sieben Tage
 * voraus). Läufe, Kraft und Rennen gehen nicht hin: intervals.icu ist hier
 * nur die Brücke zu Zwift (Jan, 13.11.2026).
 *
 * - Die Einheit geht als .zwo (derselbe `ZwoWriter` wie der Download) mit
 *   `type = VirtualRide` und `indoor = true`; ohne den Indoor-Haken nimmt
 *   Zwift sie nicht an (laut intervals-Forum, nicht in der API-Doku).
 * - Verglichen wird **lokal** über `payload_hash`; ein unveränderter Plan
 *   kostet keinen Aufruf (100 je Athlet und Tag).
 * - Geschrieben wird per Upsert über `external_id`, gelöscht per
 *   `bulk-delete`. Eine erledigte Einheit bleibt im Kalender stehen.
 * - Bekannt aus dem Forum: eine Änderung am Event kommt nicht immer in Zwift
 *   an, solange es dort schon steht. Daran kann Zone3 nichts ändern.
 */
class IntervalsWorkoutSync
{
    public const DAYS_AHEAD = 7;

    public function __construct(
        private readonly IntervalsClient $client,
        private readonly ZwoWriter $zwo,
    ) {}

    /** @return array{written:int, removed:int, unchanged:int}|null null = keine Verbindung */
    public function sync(User $user): ?array
    {
        $connection = $user->intervalsConnection;
        if (! $connection) {
            return null;
        }

        $wanted   = $this->wanted($user);
        $existing = IntervalsScheduledWorkout::where('user_id', $user->id)->get()->keyBy('external_id');
        $result   = ['written' => 0, 'removed' => 0, 'unchanged' => 0];

        try {
            $toWrite = $wanted->filter(fn ($w) => ($existing[$w['external_id']]->payload_hash ?? null) !== $w['hash']);
            $result['unchanged'] = $wanted->count() - $toWrite->count();

            if ($toWrite->isNotEmpty()) {
                $saved = collect($this->client->upsertEvents($connection, $toWrite->pluck('body')->values()->all()))
                    ->keyBy(fn ($e) => (string) ($e['external_id'] ?? ''));

                foreach ($toWrite as $w) {
                    IntervalsScheduledWorkout::updateOrCreate(
                        ['user_id' => $user->id, 'external_id' => $w['external_id']],
                        [
                            'training_session_id' => $w['session_id'],
                            'event_id'            => isset($saved[$w['external_id']]['id']) ? (string) $saved[$w['external_id']]['id'] : null,
                            'planned_date'        => $w['date'],
                            'payload_hash'        => $w['hash'],
                        ],
                    );
                    $result['written']++;
                }
            }

            $gone = $existing->reject(fn ($row) => $wanted->has($row->external_id));
            $remove = [];
            foreach ($gone as $row) {
                $session = $row->training_session_id ? TrainingSession::find($row->training_session_id) : null;
                // Vergangen oder gefahren: steht als Verlauf, nur unsere Zeile geht.
                $keep = $row->planned_date->lt(today()) || $session?->status === 'completed';
                if (! $keep) {
                    $remove[] = $row->external_id;
                }
            }

            $this->client->deleteEvents($connection, $remove);
            $result['removed'] = count($remove);
            IntervalsScheduledWorkout::where('user_id', $user->id)->whereIn('id', $gone->pluck('id'))->delete();

            $connection->update(['workouts_synced_at' => now(), 'last_error' => null, 'last_error_at' => null]);
        } catch (IntervalsException $e) {
            $connection->update([
                'last_error'    => $e->revoked() ? 'intervals.icu nimmt die Verbindung nicht mehr an' : Str::limit($e->getMessage(), 250),
                'last_error_at' => now(),
            ]);
            Log::warning('intervals.icu-Kalender: Abgleich abgebrochen', ['user_id' => $user->id, 'error' => $e->getMessage()] + $result);

            return $result;
        }

        if ($result['written'] || $result['removed']) {
            Log::info('intervals.icu-Kalender abgeglichen', ['user_id' => $user->id] + $result);
        }

        return $result;
    }

    /** Beim Trennen: was noch bevorsteht, aus dem Kalender (und damit aus Zwift) nehmen. */
    public function removeAll(User $user): void
    {
        $connection = $user->intervalsConnection;
        $upcoming   = IntervalsScheduledWorkout::where('user_id', $user->id)
            ->whereDate('planned_date', '>=', today()->toDateString())
            ->pluck('external_id')->all();

        if ($connection && $upcoming !== []) {
            try {
                $this->client->deleteEvents($connection, $upcoming);
            } catch (Throwable $e) {
                Log::warning('intervals.icu-Kalender: Einheiten beim Trennen nicht gelöscht', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        IntervalsScheduledWorkout::where('user_id', $user->id)->delete();
    }

    /**
     * Was im intervals-Kalender stehen soll, nach `external_id`.
     *
     * @return Collection<string, array{external_id:string, session_id:int, date:string, hash:string, body:array}>
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
            ->map(fn (TrainingSession $s) => $this->payload($s))
            ->keyBy('external_id');
    }

    /** @return array{external_id:string, session_id:int, date:string, hash:string, body:array} */
    public function payload(TrainingSession $session): array
    {
        $external = 'zone3-session-' . $session->id;
        $date     = $session->planned_date->format('Y-m-d');
        $seconds  = (int) round(collect($session->steps)->sum(
            fn ($s) => (float) ($s['duration_min'] ?? 0) * 60 * max(1, (int) ($s['repetitions'] ?? 1))
        ));

        $body = [
            'external_id'      => $external,
            'category'         => 'WORKOUT',
            'start_date_local' => $date . 'T00:00:00',
            'type'             => 'VirtualRide',
            'indoor'           => true,
            'name'             => $session->title,
            'description'      => trim((string) $session->description),
            'moving_time'      => $seconds ?: (int) round(((float) $session->duration_min) * 60),
            'filename'         => Str::slug('zone3-' . $date . '-' . str_replace('×', 'x', $session->title)) . '.zwo',
            'file_contents'    => $this->zwo->write($session->title, (string) $session->description, $session->steps),
        ];

        return [
            'external_id' => $external,
            'session_id'  => $session->id,
            'date'        => $date,
            'hash'        => hash('sha256', json_encode($body)),
            'body'        => $body,
        ];
    }
}
