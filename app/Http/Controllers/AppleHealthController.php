<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Services\Apple\AppleHealthImporter;
use App\Services\Apple\AppleWorkoutImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Apple Health aus der iOS-App (Stufe 2, 08.11.2026).
 *
 * Der Server kann HealthKit nicht abfragen; die App liest auf dem iPhone und
 * schickt. Ohne Einwilligung (`users.apple_health_consent_at`) nimmt der
 * Server nichts an — die Werte gehen in die Prompts, und das verlangt einen
 * eigenen Haken (Apple-Richtlinie 5.1.3, Jans Entscheidung).
 */
class AppleHealthController extends Controller
{
    /** Höchstens so viele Tage bzw. Trainings je Anfrage (Rückblick 30 bzw. 42 Tage). */
    public const MAX_DAYS = 60;

    public const MAX_WORKOUTS = 120;

    /** Einwilligung geben oder widerrufen (Profil → Verbindungen → Apple Health). */
    public function consent(Request $request, AppleHealthImporter $health): RedirectResponse
    {
        $request->validate(['enabled' => 'required|boolean']);
        $user = $request->user();

        if ($request->boolean('enabled')) {
            $request->validate(['ai_consent' => 'accepted']);
            $user->forceFill(['apple_health_consent_at' => $user->apple_health_consent_at ?? now()])->save();
        } else {
            // Widerruf löscht die Erholungswerte. Aktivitäten bleiben wie bei
            // Garmin — sie sind Trainingshistorie, kein Gesundheitswert.
            $user->forceFill([
                'apple_health_consent_at'    => null,
                'apple_health_synced_at'     => null,
                'apple_activities_synced_at' => null,
            ])->save();
            $health->forget($user);
        }

        return back();
    }

    public function metrics(Request $request, AppleHealthImporter $health): JsonResponse
    {
        $data = $request->validate([
            'days'               => 'present|array|max:' . self::MAX_DAYS,
            'days.*.date'        => 'required|date_format:Y-m-d',
            'days.*.sleep_min'   => 'nullable|numeric|min:0|max:1440',
            'days.*.hrv_ms'      => 'nullable|numeric|min:1|max:400',
            'days.*.resting_hr'  => 'nullable|numeric|min:20|max:200',
            'days.*.vo2max'      => 'nullable|numeric|min:10|max:100',
            'days.*.spo2'        => 'nullable|numeric|min:50|max:100',
            'days.*.respiration' => 'nullable|numeric|min:3|max:60',
        ]);

        $written = $health->store($request->user(), $data['days']);

        return response()->json(['accepted' => $written !== null, 'written' => $written ?? []]);
    }

    public function workouts(Request $request, AppleWorkoutImporter $workouts): JsonResponse
    {
        $data = $request->validate([
            'workouts'                   => 'present|array|max:' . self::MAX_WORKOUTS,
            'workouts.*.start'           => 'required|date',
            'workouts.*.end'             => 'required|date',
            'workouts.*.type'            => 'required|string|max:60',
            'workouts.*.duration_s'      => 'required|numeric|min:0|max:172800',
            'workouts.*.distance_m'      => 'nullable|numeric|min:0|max:1000000',
            'workouts.*.avg_hr'          => 'nullable|numeric|min:20|max:250',
            'workouts.*.max_hr'          => 'nullable|numeric|min:20|max:250',
            'workouts.*.source_name'     => 'nullable|string|max:120',
            'workouts.*.laps'            => 'nullable|array|max:300',
            'workouts.*.laps.*.duration_s' => 'required|numeric|min:0',
            'workouts.*.laps.*.distance_m' => 'nullable|numeric|min:0',
            'workouts.*.laps.*.avg_hr'   => 'nullable|numeric|min:20|max:250',
            'workouts.*.laps.*.max_hr'   => 'nullable|numeric|min:20|max:250',
            'workouts.*.stream'          => 'nullable|array|max:4000',
            'workouts.*.stream.*'        => 'array|size:2',
            'workouts.*.stream.*.*'      => 'numeric|min:0',
        ]);

        $outcomes = $workouts->store($request->user(), $data['workouts']);

        return response()->json(['accepted' => $outcomes !== null, 'outcomes' => $outcomes ?? []]);
    }

    /** Was die App wissen muss, um zu lesen und zu schicken. Geteilt als `auth.appleHealth`. */
    public static function state(?\App\Models\User $user): ?array
    {
        if (! $user?->apple_health_consent_at) {
            return null;
        }

        return [
            'metrics'          => ! $user->receivesWatchHealthMetrics(),
            'workouts'         => $user->activitySource() === Activity::SOURCE_APPLE,
            'metrics_synced'   => $user->apple_health_synced_at !== null,
            'workouts_synced'  => $user->apple_activities_synced_at !== null,
        ];
    }
}
