<?php

namespace App\Http\Controllers;

use App\Models\Workout;
use App\Services\PaceFormat;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * „Mehr" als Einstellungsliste wie in iOS (Redesign Paket 3, 28.10.2026).
 *
 * Vorher ein Sheet mit fünf Links; Verbindungen, Mitteilungen und
 * Athletenprofil lagen eine Ebene tiefer im Profil, ohne dass man von außen
 * sah, was eingerichtet ist. Die Seite liest nur — rechts in jeder Zeile
 * steht grau der Stand, geändert wird im Profil.
 */
class MoreController extends Controller
{
    public function __invoke()
    {
        $user    = Auth::user();
        $profile = $user->runnerProfile;
        $vdot    = $profile?->vdot();

        $days = collect($profile?->weekly_availability ?? [])
            ->filter(fn ($d) => ($d['available'] ?? false) && ($d['minutes'] ?? 0) > 0)
            ->count();

        return Inertia::render('More', [
            'summary' => [
                'vdot'        => $vdot ? round($vdot, 1) : null,
                'threshold'   => $profile?->threshold_speed ? PaceFormat::fromMinutes((float) $profile->threshold_speed) : null,
                'events'      => $user->events()->whereDate('event_date', '>=', today())->count(),
                'workouts'    => Workout::where('user_id', $user->id)->count(),
                'model'       => $profile?->trainingModelOn(today()) === 'norwegian' ? 'Norwegisch' : '80/20',
                'days'        => $days,
                'bike'        => (bool) $profile?->bike_enabled,
                'coach'       => $user->coach?->name,
                'garmin'      => (bool) $user->garminConnection,
                'strava'      => (bool) $user->stravaAccount,
                'telegram'    => $user->telegram_chat_id !== null,
                'calendar'    => $user->calendar_token !== null,
                'push'        => (bool) $user->push_notifications_enabled,
                'reminder'    => $user->wellbeing_reminder_time ? substr($user->wellbeing_reminder_time, 0, 5) : null,
            ],
        ]);
    }
}
