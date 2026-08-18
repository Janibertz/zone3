<?php

namespace App\Http\Controllers;

use App\Models\TrainingPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Die Wochenabfrage: passt die kommende Woche zum hinterlegten Raster?
 *
 * Das Wochenraster im Profil ist der Normalfall. Urlaub, Schichtdienst oder
 * ein voller Terminkalender sind Ausnahmen — die gehoerten bisher nirgends
 * hin. Es gab zwar `availability_overrides` je Datum, aber keine Oberflaeche
 * dafuer: die Funktion lag ungenutzt im Frontend und wurde nie aufgerufen.
 */
class WeekAvailabilityController extends Controller
{
    /** Bestaetigen, dass die kommende Woche dem Raster entspricht. */
    public function confirm(): JsonResponse
    {
        $profile = Auth::user()->runnerProfile;
        $profile?->update(['week_check_week' => self::upcomingWeekKey()]);

        return response()->json(['success' => true]);
    }

    /**
     * Abweichungen fuer einzelne Tage der kommenden Woche hinterlegen.
     * Sie landen als Ausnahmen am aktiven Plan und loesen eine
     * Neuberechnung aus.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days'                 => 'required|array|min:1|max:7',
            'days.*.date'          => 'required|date_format:Y-m-d',
            'days.*.available'     => 'required|boolean',
            'days.*.duration_min'  => 'nullable|integer|min:0|max:300',
        ]);

        $user  = Auth::user();
        $start = self::upcomingWeekStart();
        $end   = $start->addDays(6);

        $plan = TrainingPlan::where('user_id', $user->id)->where('is_active', true)->first();
        if (! $plan) {
            return response()->json(['error' => 'Kein aktiver Plan vorhanden.'], 422);
        }

        $overrides = $plan->availability_overrides ?? [];

        foreach ($data['days'] as $day) {
            $date = CarbonImmutable::parse($day['date']);

            // Nur die kommende Woche — sonst liessen sich beliebige Tage
            // ueber diesen Weg umschreiben.
            if ($date->lt($start) || $date->gt($end)) {
                continue;
            }

            $overrides[$day['date']] = [
                'available'    => (bool) $day['available'],
                'duration_min' => $day['available'] ? (int) ($day['duration_min'] ?? 60) : 0,
            ];
        }

        $plan->update(['availability_overrides' => $overrides, 'needs_plan_update' => true]);
        $user->runnerProfile?->update(['week_check_week' => self::upcomingWeekKey()]);

        return response()->json(['success' => true, 'overrides' => $overrides]);
    }

    /**
     * Ab Sonntag geht es um die naechste Woche, davor um die laufende.
     * Am Sonntag plant man den Montag, nicht den Tag selbst.
     */
    public static function upcomingWeekStart(): CarbonImmutable
    {
        $today = CarbonImmutable::today();

        return $today->isSunday()
            ? $today->addDay()->startOfWeek()
            : $today->startOfWeek();
    }

    public static function upcomingWeekKey(): string
    {
        return self::upcomingWeekStart()->format('o-\WW');
    }

    /** Steht die Abfrage an? Sonntag und Montag, einmal je Woche. */
    public static function isDue(?\App\Models\RunnerProfile $profile): bool
    {
        $today = CarbonImmutable::today();
        if (! $today->isSunday() && ! $today->isMonday()) {
            return false;
        }

        return ($profile?->week_check_week) !== self::upcomingWeekKey();
    }
}
