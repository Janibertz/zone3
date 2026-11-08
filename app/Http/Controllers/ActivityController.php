<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\TrainingSession;
use App\Services\ActivityDeletionService;
use App\Services\Fit\FitUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ActivityController extends Controller
{
    /**
     * Eine Aktivität löschen.
     *
     * Bis hierher ging das nirgends — auch nicht im Admin-Bereich. Wer eine
     * Einheit versehentlich aufgezeichnet oder zum Ausprobieren angelegt
     * hatte, wurde sie nicht mehr los, und sie zaehlte weiter in
     * Wochenumfang, Belastung und Schwellenpace.
     *
     * Das Aufraeumen macht {@see ActivityDeletionService} — es haengt mehr
     * daran als die Zeile selbst.
     */
    public function destroy(Activity $activity, ActivityDeletionService $deletion)
    {
        abort_if($activity->user_id !== Auth::id(), 403);

        $result = $deletion->delete($activity);

        return response()->json([
            'success'  => true,
            'restored' => $result['sessions_restored'],
            'deleted'  => $result['sessions_deleted'],
        ]);
    }

    /**
     * Eine FIT-Datei hochladen (Jan, 08.11.2026): für Fahrten, die keine
     * Quelle liefert — vor allem Zwift ohne Strava. Danach geht es direkt in
     * die Aktivität; war sie schon da, in die vorhandene.
     */
    public function upload(Request $request, FitUploadService $uploads)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'extensions:fit'],
        ], [
            'file.extensions' => 'Bitte eine .fit-Datei wählen.',
            'file.max'        => 'Die Datei ist größer als 20 MB.',
        ]);

        $result = $uploads->import($request->user(), (string) file_get_contents($request->file('file')->getRealPath()));

        return match ($result['result']) {
            FitUploadService::RESULT_IMPORTED,
            FitUploadService::RESULT_KNOWN      => redirect()->route('activities.show', $result['activity']),
            FitUploadService::RESULT_TOMBSTONED => back()->withErrors(['file' => 'Diese Aktivität hast du gelöscht. Sie bleibt draußen.']),
            default                             => back()->withErrors(['file' => 'Die Datei ließ sich nicht lesen. Ist es eine FIT-Datei einer Aktivität?']),
        };
    }

    public function show(Activity $activity)
    {
        abort_if($activity->user_id !== Auth::id(), 403);

        $paceZones = Auth::user()->runnerProfile?->pace_zones;

        // Find training session linked to this activity (for rating)
        $linkedSession = TrainingSession::where('activity_id', $activity->id)
            ->where('user_id', Auth::id())
            ->first();

        return Inertia::render('Activities/Show', [
            'activity' => [
                'id'                   => $activity->id,
                'strava_id'            => $activity->strava_id,
                'garmin_activity_id'   => $activity->garmin_activity_id,
                'coros_label_id'       => $activity->coros_label_id ? (string) $activity->coros_label_id : null,
                'device_name'          => $activity->device_name,
                'name'                 => $activity->name,
                'description'          => $activity->description,
                'type'                 => $activity->type,
                'distance'             => $activity->distance,
                'moving_time'          => $activity->moving_time,
                'elapsed_time'         => $activity->elapsed_time,
                'total_elevation_gain' => $activity->total_elevation_gain,
                'average_speed'        => $activity->average_speed,
                'average_watts'        => $activity->average_watts,
                'weighted_average_watts' => $activity->weighted_average_watts,
                'max_speed'            => $activity->max_speed,
                'average_heartrate'    => $activity->average_heartrate,
                'max_heartrate'        => $activity->max_heartrate,
                'start_date'           => $activity->start_date->format('Y-m-d H:i:s'),
                'location_city'        => $activity->location_city,
                'location_state'       => $activity->location_state,
                'location_country'     => $activity->location_country,
                'polyline'             => $activity->polyline['polyline'] ?? null,
                'laps'                 => $activity->laps ?? null,
            ],
            'paceZones'     => $paceZones,
            'strength'      => app(\App\Services\Strength\StrengthSets::class)->present($activity->strength_sets, $linkedSession),
            'muscleLabels'  => $activity->strength_sets ? \App\Services\Strength\Muscles::LABELS : null,
            'ftpHint'       => app(\App\Services\Bike\FtpCheck::class)
                ->forActivity($activity, Auth::user()->runnerProfile?->ftp),
            'linkedSession' => $linkedSession ? [
                'id'               => $linkedSession->id,
                'title'            => $linkedSession->title,
                'type'             => $linkedSession->type,
                'rating'           => $linkedSession->rating,
                'effort_perceived' => $linkedSession->effort_perceived,
                'feeling_notes'    => $linkedSession->feeling_notes,
                'coach_review'     => $linkedSession->coach_review,
                'reviewed_at'      => $linkedSession->reviewed_at?->toIso8601String(),
                // Entscheidet, was die Loesch-Rueckfrage ueber den Plan sagt.
                'was_unplanned'    => (bool) $linkedSession->was_unplanned,
            ] : null,
        ]);
    }

    /**
     * Die Liste, nach Wochen gruppiert (Redesign 28.10.2026).
     *
     * Gefiltert wird nach Sportart-Gruppe wie in der Statistik — der alte
     * Filter `type=Run` traf weder Trail- noch Laufbandläufe. Die Seiten
     * werden angehängt („Ältere laden"), nicht umgeblättert; deshalb kommen
     * die Wochensummen vom Server: eine Woche, die über zwei Seiten läuft,
     * hätte im Browser sonst eine halbe Summe.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $sport = $request->string('sport')->toString() ?: 'all';
        if ($sport !== 'all' && ! isset(StatisticsController::SPORT_TYPES[$sport])) {
            $sport = 'all';
        }
        $search = trim($request->string('search')->toString());

        $filtered = function () use ($user, $sport, $search) {
            $query = Activity::where('user_id', $user->id);
            if ($sport !== 'all') {
                $query->whereIn('type', StatisticsController::SPORT_TYPES[$sport]);
            }
            if ($search !== '') {
                $query->where('name', 'like', '%' . $search . '%');
            }

            return $query;
        };

        $page = $filtered()->orderByDesc('start_date')->paginate(30, Activity::SUMMARY_COLUMNS)->withQueryString();

        return Inertia::render('Activities', [
            'activities' => Inertia::merge(fn () => $page->items()),
            'weekTotals' => Inertia::deepMerge(fn () => $this->weekTotals($filtered(), collect($page->items()))),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
            ],
            'filters' => ['sport' => $sport, 'search' => $search],
        ]);
    }

    /**
     * Anzahl und Kilometer je Woche (Montag als Schlüssel) für die Wochen,
     * die auf dieser Seite vorkommen — über alle Aktivitäten der Woche, nicht
     * nur die geladenen.
     *
     * @return array<string, array{count: int, km: float}>
     */
    private function weekTotals($query, $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $from = $items->min('start_date')->copy()->startOfWeek();
        $to   = $items->max('start_date')->copy()->endOfWeek();

        return $query->whereBetween('start_date', [$from, $to])
            ->get(['start_date', 'distance'])
            ->groupBy(fn ($a) => $a->start_date->copy()->startOfWeek()->format('Y-m-d'))
            ->map(fn ($week) => [
                'count' => $week->count(),
                'km'    => round($week->sum('distance') / 1000, 1),
            ])
            ->all();
    }
}
