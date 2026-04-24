<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use App\Models\Coach;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminCoachController extends Controller
{
    public function index()
    {
        $coaches = Coach::withCount('users')->get()->map(function ($coach) {
            $userIds = $coach->users()->pluck('id');
            $stats   = AiLog::whereIn('user_id', $userIds)
                ->selectRaw('COUNT(*) as calls, SUM(cost_eur) as cost, SUM(total_tokens) as tokens')
                ->first();

            $coach->ai_calls  = (int)   ($stats->calls  ?? 0);
            $coach->ai_cost   = (float) ($stats->cost   ?? 0);
            $coach->ai_tokens = (int)   ($stats->tokens ?? 0);

            return $coach;
        });

        return Inertia::render('Admin/Coaches/Index', [
            'coaches' => $coaches,
        ]);
    }

    public function show(Coach $coach)
    {
        $coach->loadCount('users');

        $userIds = $coach->users()->pluck('id');
        $aiStats = [
            'total_calls'  => AiLog::whereIn('user_id', $userIds)->count(),
            'total_cost'   => (float) AiLog::whereIn('user_id', $userIds)->sum('cost_eur'),
            'total_tokens' => (int)   AiLog::whereIn('user_id', $userIds)->sum('total_tokens'),
        ];

        $recentUsers = $coach->users()
            ->latest()
            ->limit(8)
            ->get(['id', 'name', 'email', 'created_at']);

        return Inertia::render('Admin/Coaches/Show', [
            'coach'       => $coach,
            'aiStats'     => $aiStats,
            'recentUsers' => $recentUsers,
        ]);
    }

    public function update(Request $request, Coach $coach)
    {
        $validated = $request->validate([
            'name'               => 'required|string|max:100',
            'tagline'            => 'required|string|max:255',
            'description'        => 'required|string|max:2000',
            'personality_prompt' => 'required|string',
        ]);

        $coach->update($validated);

        return back()->with('success', 'Coach "' . $coach->name . '" wurde gespeichert.');
    }
}
