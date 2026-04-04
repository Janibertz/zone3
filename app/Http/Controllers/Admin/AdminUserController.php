<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::withCount(['activities', 'goals'])
            ->with('stravaAccount:id,user_id,username,last_synced_at')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('filter')) {
            match ($request->filter) {
                'admin'     => $query->where('is_admin', true),
                'inactive'  => $query->where('is_active', false),
                'verified'  => $query->whereNotNull('email_verified_at'),
                'onboarded' => $query->whereNotNull('onboarding_completed_at'),
                'strava'    => $query->has('stravaAccount'),
                default     => null,
            };
        }

        return Inertia::render('Admin/Users/Index', [
            'users'   => $query->paginate(25)->withQueryString(),
            'filters' => $request->only(['search', 'filter']),
        ]);
    }

    public function show(User $user)
    {
        $user->load('runnerProfile', 'stravaAccount');

        $activities = $user->activities()
            ->orderByDesc('start_date')
            ->limit(20)
            ->get(['id', 'name', 'type', 'distance', 'moving_time', 'average_heartrate', 'average_speed', 'start_date']);

        $goals = $user->goals()->orderByDesc('created_at')->get();

        $wellbeingEntries = $user->wellbeingEntries()
            ->orderByDesc('date')
            ->limit(14)
            ->get();

        $activityStats = [
            'total'         => $user->activities()->count(),
            'total_km'      => round($user->activities()->where('type', 'Run')->sum('distance') / 1000, 1),
            'total_runs'    => $user->activities()->where('type', 'Run')->count(),
            'last_activity' => $user->activities()->latest('start_date')->value('start_date'),
        ];

        return Inertia::render('Admin/Users/Show', [
            'user'             => $user,
            'activities'       => $activities,
            'goals'            => $goals,
            'wellbeingEntries' => $wellbeingEntries,
            'activityStats'    => $activityStats,
        ]);
    }

    public function toggleAdmin(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Du kannst dir selbst keine Admin-Rechte entziehen.');
        }

        $user->update(['is_admin' => !$user->is_admin]);

        return back()->with('success', $user->is_admin ? 'Admin-Rechte vergeben.' : 'Admin-Rechte entzogen.');
    }

    public function toggleActive(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Du kannst dein eigenes Konto nicht deaktivieren.');
        }

        $user->update(['is_active' => !$user->is_active]);

        return back()->with('success', $user->is_active ? 'Konto aktiviert.' : 'Konto deaktiviert.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Du kannst dein eigenes Konto nicht löschen.');
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'Nutzer gelöscht.');
    }
}
