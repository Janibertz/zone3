<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CalendarFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kalender-Abo: Profil → Verbindungen → Kalender.
 *
 * Der Feed liegt hinter einem Token statt hinter dem Login, weil
 * Kalender-Apps sich beim Abonnieren nicht anmelden können. Wer die URL
 * hat, sieht den Plan — deshalb lässt sie sich im Profil neu erzeugen
 * (die alte wird ungültig) oder ganz abschalten.
 */
class CalendarFeedController extends Controller
{
    public function show(string $token, CalendarFeed $feed): Response
    {
        $user = User::where('calendar_token', $token)->first();

        abort_unless($user && $user->is_active !== false, 404);

        return response($feed->build($user), 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="zone3.ics"',
            'Cache-Control'       => 'private, max-age=900',
        ]);
    }

    /** Erzeugt die Abo-URL — oder eine neue, dann ist die alte tot. */
    public function store(Request $request): RedirectResponse
    {
        $user    = $request->user();
        $renewed = (bool) $user->calendar_token;

        $user->forceFill(['calendar_token' => Str::random(48)])->save();

        Log::info('Kalender-Abo ' . ($renewed ? 'erneuert' : 'eingerichtet'), ['user_id' => $user->id]);

        return back()->with('success', $renewed
            ? 'Neue Kalender-Adresse erzeugt. Die alte funktioniert nicht mehr.'
            : 'Kalender-Abo eingerichtet.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['calendar_token' => null])->save();

        return back()->with('success', 'Kalender-Abo abgeschaltet.');
    }
}
