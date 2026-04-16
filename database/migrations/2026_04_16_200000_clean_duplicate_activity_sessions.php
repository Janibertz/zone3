<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove duplicate TrainingSession records for the same (user_id, activity_id).
     *
     * Root cause: when a plan was regenerated, the "already linked" check only
     * looked within the new plan (by training_plan_id), missing sessions from
     * deleted plans whose training_plan_id had been set to null. This caused
     * a new unrated duplicate to be created for each existing completed session.
     *
     * Strategy: for each duplicate group keep the session that has a rating
     * (or the one with the lowest id if none are rated), delete the rest.
     */
    public function up(): void
    {
        // Find all (user_id, activity_id) pairs that have more than one session
        $duplicates = DB::table('training_sessions')
            ->select('user_id', 'activity_id')
            ->whereNotNull('activity_id')
            ->groupBy('user_id', 'activity_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $group) {
            $sessions = DB::table('training_sessions')
                ->where('user_id', $group->user_id)
                ->where('activity_id', $group->activity_id)
                ->orderByRaw('rating IS NOT NULL DESC') // rated first
                ->orderBy('id')                         // then oldest
                ->get();

            // Keep the first (rated or oldest), delete the rest
            $keepId = $sessions->first()->id;
            $deleteIds = $sessions->skip(1)->pluck('id')->toArray();

            if (!empty($deleteIds)) {
                DB::table('training_sessions')->whereIn('id', $deleteIds)->delete();
            }
        }
    }

    public function down(): void
    {
        // Not reversible — deleted duplicates cannot be restored
    }
};
