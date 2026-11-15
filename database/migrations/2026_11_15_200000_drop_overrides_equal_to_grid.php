<?php

use App\Http\Controllers\WeekAvailabilityController;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * „Deine Woche" hat bis 15.11.2026 alle sieben Tage als Ausnahme gespeichert,
 * auch die unveraenderten — und jede Ausnahme laesst den festen Termin
 * (Laufclub) an dem Tag ausfallen. Ausnahmen, die dem Raster gleichen, aendern
 * sonst nichts; sie fallen weg, damit der Termin zurueckkommt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plans = DB::table('training_plans')
            ->join('runner_profiles', 'runner_profiles.user_id', '=', 'training_plans.user_id')
            ->where('training_plans.is_active', true)
            ->whereNotNull('training_plans.availability_overrides')
            ->select('training_plans.id', 'training_plans.availability_overrides', 'runner_profiles.weekly_availability')
            ->get();

        foreach ($plans as $plan) {
            $overrides = json_decode((string) $plan->availability_overrides, true) ?: [];
            $weekly    = json_decode((string) $plan->weekly_availability, true) ?: [];
            $kept      = [];

            foreach ($overrides as $date => $override) {
                if (! is_array($override)) {
                    $kept[$date] = $override;
                    continue;
                }

                $normal = [
                    'available'    => (bool) ($override['available'] ?? true),
                    'duration_min' => (int) ($override['duration_min'] ?? 0),
                ];

                if (! WeekAvailabilityController::matchesGrid($weekly, CarbonImmutable::parse($date), $normal)) {
                    $kept[$date] = $override;
                }
            }

            if (count($kept) !== count($overrides)) {
                DB::table('training_plans')->where('id', $plan->id)
                    ->update(['availability_overrides' => $kept === [] ? null : json_encode($kept)]);
            }
        }
    }

    public function down(): void
    {
        // Die entfernten Ausnahmen glichen dem Raster; nichts zurueckzuholen.
    }
};
