<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Die Seite meldet einmal am Tag, worin sie läuft: iOS-App, Web-App vom
 * Homescreen oder Browser (09.11.2026). Am Server lässt sich das nicht
 * erkennen — die App schickt keine eigene Kennung, ihr WebView sieht aus
 * wie Safari. Gespeichert wird je Weg nur der letzte Tag.
 */
class UsageController extends Controller
{
    public const COLUMNS = [
        'app'        => 'seen_app_on',
        'homescreen' => 'seen_homescreen_on',
        'browser'    => 'seen_browser_on',
    ];

    public function seen(Request $request): Response
    {
        $data   = $request->validate(['via' => 'required|in:' . implode(',', array_keys(self::COLUMNS))]);
        $user   = $request->user();
        $column = self::COLUMNS[$data['via']];
        $today  = now()->toDateString();

        if ($user->{$column}?->toDateString() !== $today) {
            $user->forceFill([$column => $today])->saveQuietly();
        }

        return response()->noContent();
    }
}
