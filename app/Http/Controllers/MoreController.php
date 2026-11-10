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
                // Für die erledigte Einsteiger-Checkliste (Jan, 07.11.2026).
                'beta_badge'  => (bool) $user->beta_badge_at,
                'threshold'   => $profile?->thresholdDisplay()['pace']
                    ?? ($profile?->threshold_speed ? PaceFormat::fromMinutes((float) $profile->threshold_speed) : null),
                'events'      => $user->events()->whereDate('event_date', '>=', today())->count(),
                'workouts'    => Workout::where('user_id', $user->id)->count(),
                'model'       => $profile?->trainingModelOn(today()) === 'norwegian' ? 'Norwegisch' : '80/20',
                'days'        => $days,
                'bike'        => (bool) $profile?->bike_enabled,
                'coach'       => $user->coach?->name,
                'devices'     => $this->deviceStatus($user),
                'telegram'    => $user->telegram_chat_id !== null,
                'calendar'    => $user->calendar_token !== null,
                'push'        => (bool) $user->push_notifications_enabled,
                'reminder'    => $user->wellbeing_reminder_time ? substr($user->wellbeing_reminder_time, 0, 5) : null,
            ],
        ]);
    }

    /**
     * „Geräte & Apps" als eigene Seite hinter einer Zeile auf Mehr (Jan,
     * 10.11.2026: die Kacheln mitten in der Liste machten das Menü unstimmig).
     */
    public function devices()
    {
        return Inertia::render('More/Devices', [
            'devices' => $this->deviceStatus(Auth::user()),
        ]);
    }

    /** Stand der Uhren und Datenquellen — für die Kacheln und die Zeile auf Mehr. */
    private function deviceStatus($user): array
    {
        return [
            'garmin'       => (bool) $user->garminConnection,
            // null = auf diesem Server nicht eingeschaltet (COROS_ENABLED).
            'coros'        => config('services.coros.enabled') ? (bool) $user->corosConnection : null,
            // null = Strava für diesen Athleten nicht freigeschaltet.
            'strava'       => $user->canUseStrava() ? (bool) $user->stravaAccount : null,
            'apple_health' => $user->apple_health_consent_at !== null,
            // null = nicht angeboten (Plan geht automatisch auf die Garmin-Uhr).
            'apple_watch'  => $user->offersAppleWatch() ? (bool) ($user->apple_watch_sync ?? true) : null,
        ];
    }
}
