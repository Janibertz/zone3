<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use App\Models\User;
use App\Models\WeeklyReview;
use App\Services\OpenAIService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
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
        $user->load('runnerProfile', 'stravaAccount', 'coach');

        $activities = $user->activities()
            ->orderByDesc('start_date')
            ->limit(20)
            ->get(['id', 'name', 'type', 'distance', 'moving_time', 'average_heartrate', 'average_speed', 'start_date']);

        $goals = $user->goals()->orderByDesc('created_at')->get();

        $wellbeingEntries = $user->wellbeingEntries()
            ->orderByDesc('date')
            ->limit(14)
            ->get();

        $wellbeingChart = $user->wellbeingEntries()
            ->orderBy('date')
            ->limit(30)
            ->get(['date', 'energy_level', 'mood', 'sleep_quality', 'muscle_soreness', 'stress_level'])
            ->map(fn ($e) => [
                'date'  => $e->date->toDateString(),
                'score' => round(
                    ($e->energy_level + $e->mood + $e->sleep_quality
                        + (10 - $e->muscle_soreness) + (10 - $e->stress_level)) / 5,
                    1
                ),
            ])
            ->values();

        $activityStats = [
            'total'         => $user->activities()->count(),
            'total_km'      => round($user->activities()->where('type', 'Run')->sum('distance') / 1000, 1),
            'total_runs'    => $user->activities()->where('type', 'Run')->count(),
            'last_activity' => $user->activities()->latest('start_date')->value('start_date'),
        ];

        $aiLogs = AiLog::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $aiStats = [
            'total_calls'  => AiLog::where('user_id', $user->id)->count(),
            'total_cost'   => AiLog::where('user_id', $user->id)->sum('cost_eur'),
            'total_tokens' => AiLog::where('user_id', $user->id)->sum('total_tokens'),
            'by_type'      => AiLog::where('user_id', $user->id)
                ->selectRaw('call_type, COUNT(*) as count, SUM(cost_eur) as cost')
                ->groupBy('call_type')
                ->orderByDesc('count')
                ->get(),
        ];

        return Inertia::render('Admin/Users/Show', [
            'user'             => $user,
            'activities'       => $activities,
            'goals'            => $goals,
            'wellbeingEntries' => $wellbeingEntries,
            'wellbeingChart'   => $wellbeingChart,
            'activityStats'    => $activityStats,
            'aiLogs'           => $aiLogs,
            'aiStats'          => $aiStats,
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

    public function resetRecommendation(User $user)
    {
        $user->runnerProfile?->update([
            'today_recommendation'        => null,
            'recommendation_date'         => null,
            'recommendation_wellbeing_id' => null,
        ]);

        return back()->with('success', 'Tagesempfehlung wurde zurückgesetzt.');
    }

    public function triggerWeeklyReview(User $user, OpenAIService $openAI)
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY)->subWeek()->toDateString();
        $weekEnd   = Carbon::now()->startOfWeek(Carbon::MONDAY)->subDay()->toDateString();

        WeeklyReview::where('user_id', $user->id)->where('week_start', $weekStart)->delete();

        $openAI->withCoach($user->coach?->personality_prompt);
        $openAI->forUser($user->id);
        $content = $openAI->generateWeeklyReview($user, $weekStart, $weekEnd);

        if ($content) {
            WeeklyReview::create([
                'user_id'    => $user->id,
                'week_start' => $weekStart,
                'content'    => $content,
            ]);
            return back()->with('success', 'Weekly Review für KW ' . Carbon::parse($weekStart)->weekOfYear . ' wurde generiert.');
        }

        return back()->with('error', 'Weekly Review konnte nicht generiert werden (keine Trainingsdaten vorhanden).');
    }

    public function resetPassword(User $user)
    {
        Password::sendResetLink(['email' => $user->email]);

        return back()->with('success', 'Passwort-Reset-E-Mail wurde an ' . $user->email . ' gesendet.');
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
