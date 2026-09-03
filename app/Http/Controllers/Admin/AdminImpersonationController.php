<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Als Athlet anmelden — und wieder zurück.
 *
 * Der schnellste Weg, eine Meldung zu verstehen, ist die Seite zu sehen,
 * die der Athlet sieht. „Im Trainingsplan ist heute eine Lücke" liess sich
 * bisher nur ueber Datenbankabfragen nachvollziehen; mit einem Klick waere
 * dasselbe in Sekunden sichtbar gewesen.
 *
 * Drei Regeln, und alle drei sind wichtig:
 *
 *  · **Kein Admin darf einen Admin uebernehmen.** Sonst waere das ein
 *    stiller Weg, unter fremdem Namen administrative Dinge zu tun.
 *  · **Das Beenden liegt AUSSERHALB der Admin-Routen.** Waehrend der
 *    Uebernahme ist man kein Admin mehr — eine Route hinter der
 *    Admin-Middleware koennte man nicht mehr aufrufen und saesse fest.
 *  · **Beides wird protokolliert.** Wer wen wann uebernommen hat, gehoert
 *    ins Log; das ist der Preis dafuer, dass es die Funktion gibt.
 */
class AdminImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(User $user): RedirectResponse
    {
        $admin = request()->user();

        if ($user->is_admin) {
            return back()->with('error', 'Administratoren lassen sich nicht übernehmen.');
        }

        if ($user->id === $admin->id) {
            return back()->with('error', 'Das bist du selbst.');
        }

        if (session()->has(self::SESSION_KEY)) {
            return back()->with('error', 'Es läuft bereits eine Übernahme.');
        }

        Log::warning('Admin uebernimmt einen Account', [
            'admin_id' => $admin->id,
            'user_id'  => $user->id,
        ]);

        session()->put(self::SESSION_KEY, $admin->id);
        Auth::login($user);

        return redirect()->route('dashboard')
            ->with('success', "Du siehst Zone3 jetzt als {$user->name}.");
    }

    /**
     * Zurueck in den eigenen Account.
     *
     * Diese Methode haengt bewusst nicht an der Admin-Middleware: der
     * angemeldete Nutzer ist waehrend der Uebernahme der Athlet, und der ist
     * kein Admin. Geprueft wird stattdessen die Session — nur wer eine
     * laufende Uebernahme hat, kommt hier ueberhaupt an.
     */
    public function stop(): RedirectResponse
    {
        $adminId = session()->pull(self::SESSION_KEY);

        if (! $adminId) {
            return redirect()->route('dashboard');
        }

        $admin = User::find($adminId);

        if (! $admin || ! $admin->is_admin) {
            Auth::logout();

            return redirect()->route('login');
        }

        Log::info('Admin beendet die Uebernahme', ['admin_id' => $admin->id]);

        Auth::login($admin);

        return redirect()->route('admin.users.index');
    }
}
