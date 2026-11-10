<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;

/**
 * Woher eine Anmeldung kommt (seit 10.11.2026).
 *
 * Ein Link wie `zone3.run/register?ref=instagram` oder mit `utm_source`
 * merkt sich die Quelle in der Session, solange niemand angemeldet ist.
 * Beim Anlegen des Kontos landet sie in `users.signup_source`. Wege, auf
 * denen die Session nicht mitkommt (Link per Mail in einem anderen
 * Browser, Apples Formular-Rückweg), tragen sie im Cache mit.
 *
 * Gespeichert wird nur dieses eine Wort, kein Verweis, keine Adresse.
 */
class SignupSource
{
    public const SESSION_KEY = 'signup_source';
    public const INVITATION = 'einladung';

    /** Kleinbuchstaben, Ziffern, - und _, höchstens 40 Zeichen. */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace('/[^a-z0-9_-]/', '', mb_strtolower(trim($value)));

        return $value === '' ? null : substr($value, 0, 40);
    }

    /** Aus der Anfrage merken; die erste Quelle gewinnt. */
    public static function remember(Request $request): void
    {
        if ($request->user() || ! $request->hasSession()) {
            return;
        }
        $source = self::clean($request->query('ref') ?? $request->query('utm_source'));
        if ($source && ! $request->session()->has(self::SESSION_KEY)) {
            $request->session()->put(self::SESSION_KEY, $source);
        }
    }

    public static function current(): ?string
    {
        $request = request();

        return $request->hasSession() ? self::clean($request->session()->get(self::SESSION_KEY)) : null;
    }
}
