<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Eintraege, die vor der Crew-Funktion angelegt wurden, haben keinen
 * Schluessel — ihre Crew-URL endete auf "?crew=" und war damit wertlos.
 * Hier einmalig nachtragen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('live_tracks')
            ->whereNull('crew_key')
            ->orWhere('crew_key', '')
            ->get(['id'])
            ->each(function ($row) {
                DB::table('live_tracks')
                    ->where('id', $row->id)
                    ->update(['crew_key' => Str::lower(Str::random(20))]);
            });
    }

    public function down(): void
    {
        // Schluessel bleiben bestehen — ein Zuruecknehmen waere nur schaedlich.
    }
};
