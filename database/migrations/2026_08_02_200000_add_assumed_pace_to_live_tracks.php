<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Angenommene Pace fuer die Hochrechnung der Distanz innerhalb der
 * laufenden Runde. Ohne Garmin springt die Distanz sonst nur einmal
 * pro Stunde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_tracks', function (Blueprint $table) {
            $table->unsignedSmallInteger('assumed_pace_sec')->default(420)->after('yard_km'); // 7:00 /km
        });
    }

    public function down(): void
    {
        Schema::table('live_tracks', function (Blueprint $table) {
            $table->dropColumn('assumed_pace_sec');
        });
    }
};
