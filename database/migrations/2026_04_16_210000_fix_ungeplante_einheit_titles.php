<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replace hardcoded 'Ungeplante Einheit' session titles with the
     * actual Strava activity name stored in the activities table.
     */
    public function up(): void
    {
        // Portable per-row update (not a MySQL-only UPDATE…JOIN) so this also
        // runs on the sqlite test database. Result is identical on MySQL.
        DB::table('training_sessions')
            ->join('activities', 'activities.id', '=', 'training_sessions.activity_id')
            ->where('training_sessions.title', 'Ungeplante Einheit')
            ->whereNotNull('training_sessions.activity_id')
            ->select('training_sessions.id', 'activities.name')
            ->get()
            ->each(function ($row) {
                DB::table('training_sessions')
                    ->where('id', $row->id)
                    ->update(['title' => $row->name]);
            });
    }

    public function down(): void
    {
        // Not reversible
    }
};
