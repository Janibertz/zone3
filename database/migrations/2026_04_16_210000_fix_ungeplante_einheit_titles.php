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
        DB::statement("
            UPDATE training_sessions ts
            JOIN activities a ON a.id = ts.activity_id
            SET ts.title = a.name
            WHERE ts.title = 'Ungeplante Einheit'
              AND ts.activity_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        // Not reversible
    }
};
