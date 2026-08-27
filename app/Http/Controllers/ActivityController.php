<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\TrainingSession;
use App\Services\ActivityDeletionService;
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
                'name'                 => $activity->name,
                'description'          => $activity->description,
                'type'                 => $activity->type,
                'distance'             => $activity->distance,
                'moving_time'          => $activity->moving_time,
                'elapsed_time'         => $activity->elapsed_time,
                'total_elevation_gain' => $activity->total_elevation_gain,
                'average_speed'        => $activity->average_speed,
                'average_watts'        => $activity->average_watts,
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
            'linkedSession' => $linkedSession ? [
                'id'               => $linkedSession->id,
                'title'            => $linkedSession->title,
                'type'             => $linkedSession->type,
                'rating'           => $linkedSession->rating,
                'effort_perceived' => $linkedSession->effort_perceived,
                'feeling_notes'    => $linkedSession->feeling_notes,
                // Entscheidet, was die Loesch-Rueckfrage ueber den Plan sagt.
                'was_unplanned'    => (bool) $linkedSession->was_unplanned,
            ] : null,
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Activity::where('user_id', $user->id)->orderByDesc('start_date');

        if ($request->filled('type')) {
            // "Ride" filter covers both outdoor and virtual rides
            if ($request->type === 'Ride') {
                $query->whereIn('type', ['Ride', 'VirtualRide']);
            } else {
                $query->where('type', $request->type);
            }
        }

        if ($request->filled('month')) {
            [$year, $month] = explode('-', $request->month);
            $query->whereYear('start_date', $year)->whereMonth('start_date', $month);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $activities = $query->paginate(20, Activity::SUMMARY_COLUMNS)->withQueryString();

        return Inertia::render('Activities', [
            'activities' => $activities,
            'filters' => $request->only(['type', 'month', 'search']),
        ]);
    }
}
