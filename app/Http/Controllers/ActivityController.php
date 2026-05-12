<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\TrainingSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ActivityController extends Controller
{
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
                'max_speed'            => $activity->max_speed,
                'average_heartrate'    => $activity->average_heartrate,
                'max_heartrate'        => $activity->max_heartrate,
                'start_date'           => $activity->start_date->format('Y-m-d H:i:s'),
                'location_city'        => $activity->location_city,
                'location_state'       => $activity->location_state,
                'location_country'     => $activity->location_country,
                'polyline'             => $activity->polyline['polyline'] ?? null,
            ],
            'paceZones'     => $paceZones,
            'linkedSession' => $linkedSession ? [
                'id'               => $linkedSession->id,
                'title'            => $linkedSession->title,
                'type'             => $linkedSession->type,
                'rating'           => $linkedSession->rating,
                'effort_perceived' => $linkedSession->effort_perceived,
                'feeling_notes'    => $linkedSession->feeling_notes,
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

        $activities = $query->paginate(20)->withQueryString();

        return Inertia::render('Activities', [
            'activities' => $activities,
            'filters' => $request->only(['type', 'month', 'search']),
        ]);
    }
}
