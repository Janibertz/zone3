<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\IgnoredActivity;

/**
 * Stravas Antwort in eine Aktivität übersetzen.
 *
 * Alles, was nicht nach Strava fragt — anlegen oder an eine Garmin-Zeile
 * hängen, Zuordnung zur Einheit, Review, Push —, liegt in
 * `ActivityImportService`. Hier steht nur, welche Felder Strava wie nennt.
 */
class StravaImportService
{
    public function __construct(private readonly ActivityImportService $activities) {}

    /**
     * Eine Aktivität aus Stravas Detailantwort anlegen, auffrischen oder an
     * dieselbe Einheit aus Garmin hängen.
     *
     * Gibt `null` zurück, wenn der Athlet die Aktivität gelöscht hat: der
     * Grabstein in `ignored_activities` verhindert, dass Import und Webhook
     * sie wieder hereinholen. `wasRecentlyCreated` sagt, ob sie neu ist.
     */
    public function importFromDetail(int $userId, array $activityData): ?Activity
    {
        $stravaId = $activityData['id'] ?? null;
        if (! $stravaId) {
            return null;
        }

        if (IgnoredActivity::blocks($userId, Activity::SOURCE_STRAVA, $stravaId)) {
            return null;
        }

        return $this->activities->store(
            $userId,
            Activity::SOURCE_STRAVA,
            (int) $stravaId,
            $this->attributes($activityData, detail: true),
            $this->garminIdFrom($activityData),
        );
    }

    /**
     * Die Felder einer Aktivität in Zone3s Namen.
     *
     * Der Listeneintrag (`detail: false`) trägt weder Runden noch
     * Startkoordinaten noch Gerät; die werden dann nicht überschrieben.
     */
    public function attributes(array $activityData, bool $detail = true): array
    {
        $attributes = [
            'name'                 => $activityData['name'] ?? 'Aktivität',
            'description'          => $activityData['description'] ?? null,
            'type'                 => $activityData['type'] ?? 'Run',
            'distance'             => $activityData['distance'] ?? 0,
            'moving_time'          => $activityData['moving_time'] ?? 0,
            'elapsed_time'         => $activityData['elapsed_time'] ?? 0,
            'total_elevation_gain' => $activityData['total_elevation_gain'] ?? 0,
            'average_speed'        => $activityData['average_speed'] ?? 0,
            'average_watts'        => $activityData['average_watts'] ?? null,
            'weighted_average_watts' => $activityData['weighted_average_watts'] ?? null,
            'device_watts'         => isset($activityData['device_watts']) ? (bool) $activityData['device_watts'] : null,
            'max_speed'            => $activityData['max_speed'] ?? 0,
            'average_heartrate'    => $activityData['average_heartrate'] ?? null,
            'max_heartrate'        => $activityData['max_heartrate'] ?? null,
            'start_date'           => $activityData['start_date'] ?? now(),
            'location_city'        => $activityData['location_city'] ?? null,
            'location_state'       => $activityData['location_state'] ?? null,
            'location_country'     => $activityData['location_country'] ?? null,
            'polyline'             => $this->extractPolyline($activityData),
        ];

        if ($detail) {
            $attributes += [
                'laps'        => ! empty($activityData['laps']) ? $this->normalizeLaps($activityData['laps']) : null,
                'start_lat'   => $activityData['start_latlng'][0] ?? null,
                'start_lng'   => $activityData['start_latlng'][1] ?? null,
                'device_name' => $activityData['device_name'] ?? null,
            ];
        }

        return $attributes;
    }

    /**
     * Die Garmin-ID, wenn Garmin die Aktivität zu Strava hochgeladen hat.
     *
     * Strava nennt dann eine `external_id` der Form `garmin_push_<ID>`.
     * Das Format ist nicht dokumentiert, nur an Uploads beobachtet — passt
     * es nicht, greift der unscharfe Abgleich über Startzeit und Distanz.
     */
    public function garminIdFrom(array $activityData): ?int
    {
        $external = (string) ($activityData['external_id'] ?? '');

        return preg_match('/^garmin_push_(\d+)$/', $external, $m) ? (int) $m[1] : null;
    }

    /**
     * Store the activity's start coordinates (from Strava's start_latlng)
     * so the weather feature can resolve the user's training location.
     */
    public function applyStartCoords(Activity $activity, array $detail): void
    {
        $latlng = $detail['start_latlng'] ?? null;
        if (is_array($latlng) && count($latlng) === 2 && $latlng[0] !== null) {
            $activity->start_lat = $latlng[0];
            $activity->start_lng = $latlng[1];
            $activity->save();
        }
    }

    /**
     * Normalize raw Strava lap data to a compact format for storage and display.
     * Keeps only the fields needed for the UI.
     */
    public function normalizeLaps(array $rawLaps): array
    {
        return array_map(fn ($lap) => [
            'index'             => $lap['lap_index']         ?? null,
            'name'              => $lap['name']              ?? null,
            'elapsed_time'      => $lap['elapsed_time']      ?? 0,
            'moving_time'       => $lap['moving_time']       ?? 0,
            'distance'          => $lap['distance']          ?? 0,
            'average_speed'     => $lap['average_speed']     ?? null,
            'max_speed'         => $lap['max_speed']         ?? null,
            'average_heartrate' => $lap['average_heartrate'] ?? null,
            'max_heartrate'     => $lap['max_heartrate']     ?? null,
            'pace_zone'         => $lap['pace_zone']         ?? null,
        ], $rawLaps);
    }

    /**
     * Extract the best available polyline from Strava activity data.
     * List endpoint → summary_polyline only.
     * Single activity endpoint → polyline (detailed) preferred, fallback summary_polyline.
     * Returns null if neither is present or both are empty strings.
     */
    public function extractPolyline(array $activityData): ?array
    {
        $poly = $activityData['map']['polyline'] ?? null;
        if (empty($poly)) {
            $poly = $activityData['map']['summary_polyline'] ?? null;
        }
        return $poly ? ['polyline' => $poly] : null;
    }
}
