<?php

namespace App\Services\Garmin;

use App\Jobs\GenerateSessionReviewJob;
use App\Models\Activity;
use App\Models\GarminConnection;
use App\Models\GarminWebhookEvent;
use App\Models\IgnoredActivity;
use App\Models\TrainingSession;
use App\Services\ActivityDeletionService;
use App\Services\ActivityImportService;
use App\Services\BestEffortService;
use App\Services\Polyline;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Aktivitäten aus einem Activity-Push in `activities`.
 *
 * Drei Arten kommen an dieselbe URL:
 *
 *  · `activities` — die Zusammenfassung, kurz nach dem Hochladen der Uhr.
 *  · `activityDetails` — dieselbe Zusammenfassung plus Messpunkte und
 *    Rundenstarts. Daraus entstehen Strecke auf der Karte, Runden und
 *    Bestzeiten.
 *  · `manuallyUpdatedActivities` — der Athlet hat in Garmin Connect etwas
 *    geändert. Garmin führt dann, wie Strava bei einem `update`.
 *
 * Welche zuerst kommt, ist nicht festgelegt. Die erste legt an, die zweite
 * frischt auf — Zuordnung, Review und Push laufen nur beim Anlegen.
 *
 * Alles, was nicht nach Garmin fragt, macht `ActivityImportService`, auch
 * das Anhängen an dieselbe Einheit aus Strava. Hier steht nur, wie Garmin
 * die Felder nennt — nach der Activity API Specification 2.0 (PDF im
 * Portal). Jeder Push bleibt trotzdem 14 Tage roh in
 * `garmin_webhook_events.payload`, die Messpunkte gekürzt.
 */
class GarminActivityImporter
{
    public const TYPES = ['activities', 'activityDetails', 'manuallyUpdatedActivities'];

    /** So viele Punkte trägt die Linie höchstens — für die Karte reicht das. */
    private const MAX_POLYLINE_POINTS = 2000;

    public function __construct(
        private readonly ActivityImportService $activities,
        private readonly BestEffortService $bestEfforts,
    ) {}

    /**
     * @return array<string, int> je Ergebnis die Zahl der Aktivitäten
     */
    public function import(array $payload): array
    {
        $outcomes = [];

        foreach (self::TYPES as $type) {
            foreach ((array) ($payload[$type] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $outcome = $this->importOne($type, $item);
                $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
            }
        }

        return $outcomes;
    }

    private function importOne(string $type, array $item): string
    {
        $garminUserId = (string) ($item['userId'] ?? '');
        $connection   = $garminUserId !== '' ? GarminConnection::where('garmin_user_id', $garminUserId)->first() : null;

        $event = GarminWebhookEvent::create([
            'type'           => $type,
            'garmin_user_id' => $garminUserId ?: null,
            'user_id'        => $connection?->user_id,
            'payload'        => json_encode($this->forLog($item)),
        ]);

        $done = function (string $outcome, ?string $note = null) use ($event): string {
            $event->update(['outcome' => $outcome, 'note' => $note]);

            return $outcome;
        };

        if (! $connection) {
            return $done(GarminWebhookEvent::OUTCOME_UNKNOWN_USER);
        }

        if (! $connection->allows(GarminConnection::ACTIVITY)) {
            return $done(GarminWebhookEvent::OUTCOME_NOT_PERMITTED);
        }

        // Details tragen die Zusammenfassung eine Ebene tiefer.
        $summary    = $type === 'activityDetails' ? (array) ($item['summary'] ?? []) : $item;
        $activityId = $this->activityId($item);

        if ($activityId <= 0 || ! isset($summary['startTimeInSeconds'])) {
            return $done(GarminWebhookEvent::OUTCOME_NOTHING_TO_STORE);
        }

        // Ein Mehrsport-Rahmen (Triathlon): seine Teile kommen einzeln und
        // werden einzeln gezählt. Der Rahmen selbst wäre jeder Kilometer
        // noch einmal.
        if (! empty($summary['isParent'])) {
            return $done(GarminWebhookEvent::OUTCOME_NOTHING_TO_STORE, 'Mehrsport-Rahmen');
        }

        $user   = $connection->user;
        $userId = $connection->user_id;

        // Eine Quelle pro Athlet. Kommen die Aktivitäten von Strava, legt
        // Garmin nichts an — sonst stünde jeder Lauf zweimal im Plan.
        if ($user->activitySource() !== Activity::SOURCE_GARMIN) {
            return $done(GarminWebhookEvent::OUTCOME_OTHER_SOURCE);
        }

        if (IgnoredActivity::blocks($userId, Activity::SOURCE_GARMIN, $activityId)) {
            return $done(GarminWebhookEvent::OUTCOME_TOMBSTONED);
        }

        $samples    = $type === 'activityDetails' ? array_values(array_filter((array) ($item['samples'] ?? []), 'is_array')) : [];
        $attributes = array_merge($this->attributes($summary), $this->fromSamples($samples, (array) ($item['laps'] ?? [])));

        $before = Activity::where('user_id', $userId)->where('garmin_activity_id', $activityId)->first();

        // Die Zusammenfassung kennt nur die Gesamtdauer. Kam sie nach den
        // Details, überschriebe sie deren Bewegungszeit.
        if ($samples === [] && $before) {
            unset($attributes['moving_time']);
        }

        $activity = $this->activities->store($userId, Activity::SOURCE_GARMIN, $activityId, $attributes);
        $efforts  = BestEffortService::effortsFromStream($this->stream($samples));

        if ($activity->wasRecentlyCreated) {
            $this->activities->processNew(
                $activity,
                $samples !== [] ? fn (Activity $a) => $this->bestEfforts->syncFromActivityData($a, ['best_efforts' => $efforts]) : null,
            );
            $this->activities->dispatchPlanRegenerationIfNeeded($userId);

            Log::info('Garmin-Aktivitaet importiert', [
                'user_id'            => $userId,
                'garmin_activity_id' => $activityId,
                'type'               => $activity->type,
                'name'               => $activity->name,
            ]);

            return $done(GarminWebhookEvent::OUTCOME_IMPORTED, $activity->name);
        }

        if (! $before) {
            // Dieselbe Einheit kam schon aus Strava — nur angehängt.
            return $done(GarminWebhookEvent::OUTCOME_ATTACHED, $activity->name);
        }

        if ($activity->source === Activity::SOURCE_GARMIN) {
            $this->followUpdate($activity, $before->type);

            // Die Zusammenfassung kam vor den Details: die Bestzeiten erst jetzt.
            if ($samples !== [] && $activity->type === 'Run' && $activity->best_efforts_synced_at === null) {
                if ($this->bestEfforts->syncFromActivityData($activity, ['best_efforts' => $efforts]) !== []) {
                    $this->activities->flagPendingPr($userId, $activity->id);
                }
            }
        }

        return $done(GarminWebhookEvent::OUTCOME_UPDATED, $activity->name);
    }

    /**
     * Was eine geänderte Aktivität an den Einheiten ändert — derselbe Weg wie
     * beim Strava-`update`: andere Sportart heißt neu zuordnen, sonst wandern
     * nur die Zahlen mit.
     */
    private function followUpdate(Activity $activity, string $typeBefore): void
    {
        if (Activity::sportFamily($typeBefore) === Activity::sportFamily($activity->type)) {
            $this->activities->syncSessionsWithActivity($activity);

            return;
        }

        app(ActivityDeletionService::class)->unlink($activity);
        $this->activities->matchActivityToSession($activity->user_id, $activity);

        TrainingSession::where('user_id', $activity->user_id)
            ->where('activity_id', $activity->id)
            ->where('status', 'completed')
            ->whereNull('reviewed_at')
            ->pluck('id')
            ->each(fn ($id) => GenerateSessionReviewJob::dispatch($id)->delay(now()->addSeconds(20)));
    }

    /**
     * Die Garmin-ID der Aktivität. `manuallyUpdatedActivities` trägt laut
     * Spezifikation kein `activityId`, nur die `summaryId` — bei Aktivitäten
     * ist sie dieselbe Zahl, bei Details mit angehängtem „-detail".
     */
    private function activityId(array $item): int
    {
        if (isset($item['activityId']) && is_numeric($item['activityId'])) {
            return (int) $item['activityId'];
        }

        return preg_match('/^(\d+)/', (string) ($item['summaryId'] ?? ''), $m) ? (int) $m[1] : 0;
    }

    /** Die Felder der Zusammenfassung in Zone3s Namen. */
    public function attributes(array $summary): array
    {
        $duration = (int) ($summary['durationInSeconds'] ?? 0);
        $distance = (float) ($summary['distanceInMeters'] ?? 0);
        $type     = GarminActivityType::toInternal($summary['activityType'] ?? null);

        return [
            'name'                 => trim((string) ($summary['activityName'] ?? '')) ?: 'Aktivität',
            'type'                 => $type,
            'distance'             => $distance,
            'moving_time'          => $duration,
            'elapsed_time'         => $duration,
            'total_elevation_gain' => (float) ($summary['totalElevationGainInMeters'] ?? 0),
            'average_speed'        => (float) ($summary['averageSpeedInMetersPerSecond'] ?? ($duration > 0 ? $distance / $duration : 0)),
            'max_speed'            => (float) ($summary['maxSpeedInMetersPerSecond'] ?? 0),
            'average_heartrate'    => $summary['averageHeartRateInBeatsPerMinute'] ?? null,
            'max_heartrate'        => $summary['maxHeartRateInBeatsPerMinute'] ?? null,
            'start_date'           => Carbon::createFromTimestampUTC((int) $summary['startTimeInSeconds']),
            'start_lat'            => $summary['startingLatitudeInDegree'] ?? null,
            'start_lng'            => $summary['startingLongitudeInDegree'] ?? null,
            // „unknown" bei manuell angelegten Aktivitäten (Spezifikation).
            'device_name'          => ($summary['deviceName'] ?? 'unknown') !== 'unknown' ? $summary['deviceName'] : null,
        ];
    }

    /**
     * Linie, Runden und Bewegungszeit aus den Messpunkten. Leer, wenn keine
     * kamen — dann bleibt stehen, was die Zeile schon hat.
     */
    public function fromSamples(array $samples, array $laps): array
    {
        if ($samples === []) {
            return [];
        }

        $attributes = [];

        $points = [];
        foreach ($samples as $s) {
            if (isset($s['latitudeInDegree'], $s['longitudeInDegree'])) {
                $points[] = [(float) $s['latitudeInDegree'], (float) $s['longitudeInDegree']];
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

        $moving = collect($samples)->pluck('movingDurationInSeconds')->filter(fn ($v) => $v !== null)->max();
        if ($moving > 0) {
            $attributes['moving_time'] = (int) $moving;
        }

        $lapStarts = collect($laps)->pluck('startTimeInSeconds')->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v)->sort()->values()->all();
        if ($lapStarts !== []) {
            $attributes['laps'] = $this->laps($samples, $lapStarts);
        }

        return $attributes;
    }

    /**
     * Runden aus den Messpunkten zwischen zwei Rundenstarts, in der Form,
     * die `StravaImportService::normalizeLaps()` für die Oberfläche schreibt.
     */
    private function laps(array $samples, array $lapStarts): array
    {
        $timed = array_values(array_filter($samples, fn ($s) => isset($s['startTimeInSeconds'])));
        usort($timed, fn ($a, $b) => $a['startTimeInSeconds'] <=> $b['startTimeInSeconds']);

        $laps = [];
        $last = count($lapStarts) - 1;

        foreach ($lapStarts as $i => $start) {
            $next = $i < $last ? $lapStarts[$i + 1] : null;

            $in = array_values(array_filter($timed, fn ($s) => $s['startTimeInSeconds'] >= $start
                && ($next === null || $s['startTimeInSeconds'] < $next)));

            if ($in === []) {
                continue;
            }

            // Die Runde endet, wo die nächste beginnt: deren erster Punkt
            // schliesst die Strecke, sonst fehlte zwischen zwei Runden je
            // ein Messintervall.
            $first  = $in[0];
            $closer = $next !== null
                ? (collect($timed)->first(fn ($s) => $s['startTimeInSeconds'] >= $next) ?? end($in))
                : end($in);

            $elapsed  = max(0, (int) (($next ?? $closer['startTimeInSeconds']) - $start));
            $distance = max(0.0, (float) ($closer['totalDistanceInMeters'] ?? 0) - (float) ($first['totalDistanceInMeters'] ?? 0));
            $moving   = isset($closer['movingDurationInSeconds'], $first['movingDurationInSeconds'])
                ? max(0, (int) $closer['movingDurationInSeconds'] - (int) $first['movingDurationInSeconds'])
                : $elapsed;

            $hr     = collect($in)->pluck('heartRate')->filter(fn ($v) => $v > 0);
            $speeds = collect($in)->pluck('speedMetersPerSecond')->filter(fn ($v) => $v !== null);

            $laps[] = [
                'index'             => $i + 1,
                'name'              => 'Runde ' . ($i + 1),
                'elapsed_time'      => $elapsed,
                'moving_time'       => $moving,
                'distance'          => round($distance, 1),
                'average_speed'     => $moving > 0 ? round($distance / $moving, 3) : null,
                'max_speed'         => $speeds->isNotEmpty() ? (float) $speeds->max() : null,
                'average_heartrate' => $hr->isNotEmpty() ? round($hr->avg(), 1) : null,
                'max_heartrate'     => $hr->isNotEmpty() ? (int) $hr->max() : null,
                'pace_zone'         => null,
            ];
        }

        return $laps;
    }

    /** [Sekunden, Meter gesamt] für die Bestzeiten. */
    private function stream(array $samples): array
    {
        $points = [];
        foreach ($samples as $s) {
            if (isset($s['startTimeInSeconds'], $s['totalDistanceInMeters'])) {
                $points[] = [(int) $s['startTimeInSeconds'], (float) $s['totalDistanceInMeters']];
            }
        }
        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);

        return $points;
    }

    /**
     * Was ins Protokoll kommt: alles außer der vollen Messpunktliste. Zwei
     * Stunden mit einem Punkt pro Sekunde wären Megabytes je Lauf; für die
     * Frage „wie heißen die Felder" reichen die ersten drei.
     */
    private function forLog(array $item): array
    {
        if (isset($item['samples']) && is_array($item['samples'])) {
            $item['samples_count'] = count($item['samples']);
            $item['samples']       = array_slice($item['samples'], 0, 3);
        }

        return $item;
    }
}
