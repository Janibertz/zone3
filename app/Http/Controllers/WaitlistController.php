<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Inertia\Inertia;

/** Die eine Seite, die ein Konto auf der Warteliste sieht. */
class WaitlistController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        if (! $user->isWaitlisted()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Waitlist', [
            'name'  => $user->name,
            'email' => $user->email,
            'since' => $user->waitlisted_at->toIso8601String(),
            'vapidPublicKey' => config('services.webpush.public_key'),
        ])->toResponse($request);
    }
}
