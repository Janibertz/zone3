<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Gemeinsamer Abschluss für Apple und den Link per Mail. */
trait PasswordlessLogin
{
    /** Meldet an (dauerhaft — in der App gibt es kein „Angemeldet bleiben“) und sagt, wohin. */
    protected function signIn(Request $request, User $user): string
    {
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        if ($user->isWaitlisted()) {
            return route('waitlist');
        }

        return $request->session()->pull('url.intended', route('dashboard'));
    }
}
