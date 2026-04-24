<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminAiLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AiLog::with('user:id,name,email')
            ->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('call_type')) {
            $query->where('call_type', $request->call_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->paginate(50)->withQueryString();

        // KPI stats (last 24h)
        $since24h = now()->subHours(24);
        $kpis = [
            'calls_today'    => AiLog::whereDate('created_at', today())->count(),
            'tokens_today'   => AiLog::whereDate('created_at', today())->sum('total_tokens'),
            'cost_today'     => AiLog::whereDate('created_at', today())->sum('cost_eur'),
            'avg_duration'   => (int) AiLog::whereDate('created_at', today())->avg('duration_ms'),
            'errors_today'   => AiLog::whereDate('created_at', today())->where('status', 'error')->count(),
            'calls_total'    => AiLog::count(),
            'cost_total'     => AiLog::sum('cost_eur'),
        ];

        // Calls per day (last 30 days)
        $callsPerDay = AiLog::selectRaw("DATE(created_at) as date, COUNT(*) as calls, SUM(total_tokens) as tokens, SUM(cost_eur) as cost")
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Distribution by call type (all time)
        $byType = AiLog::selectRaw('call_type, COUNT(*) as count, SUM(total_tokens) as tokens, SUM(cost_eur) as cost')
            ->groupBy('call_type')
            ->orderByDesc('count')
            ->get();

        // Top 10 users by cost
        $topUsers = AiLog::selectRaw('user_id, COUNT(*) as calls, SUM(total_tokens) as tokens, SUM(cost_eur) as cost')
            ->with('user:id,name,email')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('cost')
            ->limit(10)
            ->get();

        $callTypes = AiLog::distinct()->pluck('call_type')->sort()->values();
        $users     = User::orderBy('name')->get(['id', 'name', 'email']);

        return Inertia::render('Admin/AiLogs/Index', [
            'logs'        => $logs,
            'kpis'        => $kpis,
            'callsPerDay' => $callsPerDay,
            'byType'      => $byType,
            'topUsers'    => $topUsers,
            'callTypes'   => $callTypes,
            'users'       => $users,
            'filters'     => $request->only(['user_id', 'call_type', 'status', 'date_from', 'date_to']),
        ]);
    }

    public function show(AiLog $aiLog)
    {
        $aiLog->load('user:id,name,email');

        return Inertia::render('Admin/AiLogs/Show', [
            'log' => $aiLog,
        ]);
    }
}
