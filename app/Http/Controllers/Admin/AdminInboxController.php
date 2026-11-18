<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workout;
use App\Services\Admin\AdminInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das Postfach (18.11.2026): Warteliste, Hilfe & Feedback, Kontaktformular
 * und gemeldete Workouts in einer Liste. Jeder Eintrag behält seinen Weg —
 * die Aktionen gehen auf die bestehenden Routen.
 */
class AdminInboxController extends Controller
{
    public function index(Request $request, AdminInsights $insights): Response
    {
        $done = $request->query('tab') === 'done';

        $open = $insights->inbox(open: true);

        return Inertia::render('Admin/Inbox/Index', [
            'items'  => $done ? $insights->inbox(open: false) : $open,
            'tab'    => $done ? 'done' : 'open',
            'counts' => [
                'open'     => count($open),
                'waitlist' => collect($open)->where('kind', 'waitlist')->count(),
                'ticket'   => collect($open)->where('kind', 'ticket')->count(),
                'contact'  => collect($open)->where('kind', 'contact')->count(),
                'report'   => collect($open)->where('kind', 'report')->count(),
            ],
        ]);
    }

    /** Die Meldungen lassen: das Workout bleibt freigegeben. */
    public function keepWorkout(Request $request, Workout $workout): RedirectResponse
    {
        $workout->reports()->whereNull('dismissed_at')->update(['dismissed_at' => now()]);

        Log::info('Workout-Meldung gelassen', ['admin_id' => $request->user()->id, 'workout_id' => $workout->id]);

        return back()->with('success', 'Die Freigabe bleibt.');
    }
}
