<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NewsletterController extends Controller
{
    /** Public unsubscribe via token link (no login required). */
    public function unsubscribe(string $token)
    {
        $user = User::where('unsubscribe_token', $token)->firstOrFail();

        if (! $user->newsletter_opt_in) {
            return Inertia::render('Newsletter/Unsubscribed', [
                'alreadyUnsubscribed' => true,
                'name' => $user->name,
            ]);
        }

        $user->update(['newsletter_opt_in' => false]);

        return Inertia::render('Newsletter/Unsubscribed', [
            'alreadyUnsubscribed' => false,
            'name' => $user->name,
            'token' => $token,
        ]);
    }

    /** Resubscribe via token (from the unsubscribe confirmation page). */
    public function resubscribe(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $user = User::where('unsubscribe_token', $request->token)->firstOrFail();
        $user->update(['newsletter_opt_in' => true]);

        return back()->with('success', 'Du hast den Newsletter wieder abonniert.');
    }

    /** Update newsletter preference from the profile page (authenticated). */
    public function updatePreference(Request $request)
    {
        $request->validate(['newsletter_opt_in' => 'required|boolean']);
        $request->user()->update(['newsletter_opt_in' => $request->newsletter_opt_in]);
        return back()->with('success', $request->newsletter_opt_in ? 'Newsletter aktiviert.' : 'Newsletter deaktiviert.');
    }
}
