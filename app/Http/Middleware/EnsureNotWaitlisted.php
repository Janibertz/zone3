<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ein Konto auf der Warteliste sieht nur die Warteliste (siehe
 * {@see \App\Services\Auth\Access}). Hängt an der ganzen `web`-Gruppe,
 * damit keine Route es vergessen kann.
 *
 * Durch dürfen: Abmelden und Push anmelden — die Freischaltung kommt per
 * Push an, sonst müsste man nachsehen.
 */
class EnsureNotWaitlisted
{
    private const ALLOWED = ['waitlist', 'logout', 'push.subscribe', 'push.unsubscribe', 'push.native', 'privacy', 'impressum', 'contact.store', 'impersonate.stop'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isWaitlisted() || in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'Dein Zugang ist noch nicht freigeschaltet.');
        }

        return redirect()->route('waitlist');
    }
}
