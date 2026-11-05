<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein YouTube-Video je Kraftübung (05.11.2026, Jan: „Könnten Videos von
 * Youtube eingebunden werden? … sodass man in der App bleibt"). Gespeichert
 * wird nur die Video-ID; der Admin fügt einen beliebigen YouTube-Link ein.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strength_exercises', function (Blueprint $table) {
            $table->string('youtube_id', 20)->nullable()->after('garmin_name');
        });
    }

    public function down(): void
    {
        Schema::table('strength_exercises', function (Blueprint $table) {
            $table->dropColumn('youtube_id');
        });
    }
};
