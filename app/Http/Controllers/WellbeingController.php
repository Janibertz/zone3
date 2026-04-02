<?php

namespace App\Http\Controllers;

use App\Models\WellbeingEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        return response()->json([
            'success' => true,
            'message' => 'Wellbeing Eintrag gespeichert! 💪',
            'entry' => $entry,
            'status' => $entry->getStatus(),
            'score' => $entry->getWellbeingScore(),
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
}
