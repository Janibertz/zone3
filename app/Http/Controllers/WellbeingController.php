<?php

namespace App\Http\Controllers;

use App\Jobs\AdjustPlanForWellbeingJob;
use App\Jobs\SyncGarminHealthJob;
use App\Models\GarminDailyMetric;
use App\Models\TrainingSession;
use App\Models\WellbeingEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class WellbeingController extends Controller
{
    /**
     * Get today's wellbeing entry or create new
     */
    public function today()
    {
        $user = Auth::user();
        $today = Carbon::today();
        
        $entry = $user->wellbeingEntries()
            ->whereDate('date', $today)
            ->first();

        if (!$entry) {
            $entry = new WellbeingEntry([
                'date' => $today,
                'energy_level' => 5,
                'mood' => 5,
                'sleep_quality' => 5,
                'muscle_soreness' => 5,
                'stress_level' => 5,
                'is_sick' => false,
                'is_injured' => false,
            ]);
        }

        return $entry;
    }

    /**
     * Store wellbeing entry for today
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'energy_level' => 'required|integer|min:1|max:10',
            'mood' => 'required|integer|min:1|max:10',
            'sleep_quality' => 'required|integer|min:1|max:10',
            'muscle_soreness' => 'required|integer|min:1|max:10',
            'stress_level' => 'required|integer|min:1|max:10',
            'notes' => 'nullable|string|max:500',
            'is_sick' => 'boolean',
            'is_injured' => 'boolean',
        ]);

        $user = Auth::user();
        $today = Carbon::today();
        
        // Find existing entry for today or create new
        $entry = $user->wellbeingEntries()
            ->whereDate('date', $today)
            ->first() ?? new WellbeingEntry(['date' => $today]);
        
        $entry->fill($validated);
        $entry->user_id = $user->id;
        $entry->save();

        // Der Check-in ist das verlässlichste Signal dafür, dass jemand wach
        // ist und sein Handy benutzt — und damit auch, dass die Uhr ihre
        // Nachtwerte inzwischen zu Garmin Connect übertragen hat. Der
        // nächtliche Lauf um 06:00 ist dafür meist zu früh.
        $garminQueued = $this->refreshGarminIfStale($user);

        // Auto-adjust today's planned session if an active plan exists
        $today = Carbon::today()->toDateString();
        $hasPlannedSession = TrainingSession::where('user_id', $user->id)
            ->where('planned_date', $today)
            ->where('status', 'planned')
            ->where('type', '!=', 'rest')
            ->whereHas('trainingPlan', fn ($q) => $q->where('is_active', true))
            ->exists();

        if ($hasPlannedSession) {
            AdjustPlanForWellbeingJob::dispatch($user->id, $entry->id);
        }

        $message = $hasPlannedSession
            ? 'Wellbeing gespeichert! KI passt deine heutige Trainingseinheit an. 🤖'
            : 'Wellbeing Eintrag gespeichert! 💪';

        return response()->json([
            'success'       => true,
            'message'       => $message,
            'entry'         => $entry,
            'status'        => $entry->getStatus(),
            'score'         => $entry->getWellbeingScore(),
            'plan_adjusted' => $hasPlannedSession,
            // Sagt dem Dashboard, dass es die Garmin-Werte gleich noch einmal
            // holen soll — der Abruf laeuft in der Queue und braucht einen Moment.
            'garmin_queued' => $garminQueued,
        ]);
    }

    /**
     * Get last N wellbeing entries
     */
    public function latest($count = 7)
    {
        $user = Auth::user();
        
        $entries = $user->wellbeingEntries()
            ->orderBy('date', 'desc')
            ->limit($count)
            ->get()
            ->map(function ($entry) {
                return [
                    'date' => $entry->date->format('d.m.Y'),
                    'score' => $entry->getWellbeingScore(),
                    'status' => $entry->getStatus(),
                    'is_sick' => $entry->is_sick,
                    'is_injured' => $entry->is_injured,
                ];
            });

        return response()->json($entries);
    }

    /**
     * Get today's entry with wellbeing status
     */
    public function status()
    {
        $user = Auth::user();
        $today = Carbon::today();
        
        $entry = $user->wellbeingEntries()
            ->whereDate('date', $today)
            ->first();

        if (!$entry) {
            return response()->json([
                'exists' => false,
                'status' => '📝 Bitte Wellbeing eintragen',
                'score' => null,
            ]);
        }

        return response()->json([
            'exists' => true,
            'status' => $entry->getStatus(),
            'score' => $entry->getWellbeingScore(),
            'entry' => $entry,
        ]);
    }

    /**
     * Garmin-Erholungsdaten nachziehen, wenn die heutigen fehlen.
     *
     * Bewusst gedrosselt: der Check-in laesst sich beliebig oft speichern,
     * und jeder Abruf geht ueber den fit-service bis zu Garmin. Deshalb nur,
     * wenn fuer heute noch nichts da ist und der letzte Versuch mindestens
     * eine Viertelstunde zurueckliegt.
     */
    private function refreshGarminIfStale($user): bool
    {
        if (empty($user->garmin_session)) {
            return false;
        }

        $hasToday = GarminDailyMetric::where('user_id', $user->id)
            ->whereDate('date', Carbon::today())
            ->exists();

        if ($hasToday) {
            return false;
        }

        $lock = "garmin-sync-throttle:{$user->id}";
        if (Cache::has($lock)) {
            return false;
        }
        Cache::put($lock, true, now()->addMinutes(15));

        // Zwei Tage reichen fuer eine Morgen-Aktualisierung und sind
        // deutlich schneller als der naechtliche Sieben-Tage-Lauf.
        SyncGarminHealthJob::dispatch($user->id, 2);

        return true;
    }
}
