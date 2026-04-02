<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Activity::where('user_id', $user->id)->orderByDesc('start_date');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
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
