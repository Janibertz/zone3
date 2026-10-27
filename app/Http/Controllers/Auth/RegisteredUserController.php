<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registrieren geht nur mit einem gültigen Einladungslink.
 *
 * Ohne oder mit verbrauchtem Link zeigt /register den Hinweis
 * „nur auf Einladung" statt des Formulars. Geprüft wird beim Absenden
 * noch einmal — die Seite kann seit Tagen offen sein.
 */
class RegisteredUserController extends Controller
{
    public function create(Request $request): Response
    {
        $token = (string) $request->query('invite', '');

        return Inertia::render('Auth/Register', [
            'invite' => $token !== '' && $this->usable($token) ? $token : null,
            'inviteGiven' => $token !== '',
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'invite' => 'required|string|max:64',
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'invite.required' => 'Zone3 ist nur auf Einladung.',
        ]);

        // Sperre auf der Zeile: zwei gleichzeitige Absendungen mit demselben
        // Link dürfen nicht beide ein Konto bekommen.
        $user = DB::transaction(function () use ($request) {
            $invitation = Invitation::where('token', $request->invite)->lockForUpdate()->first();

            if (! $invitation?->isUsable()) {
                throw ValidationException::withMessages([
                    'invite' => 'Dieser Einladungslink ist nicht mehr gültig.',
                ]);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $invitation->update(['used_at' => now(), 'used_by' => $user->id]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('onboarding', absolute: false));
    }

    private function usable(string $token): bool
    {
        return Invitation::where('token', $token)->usable()->exists();
    }
}
