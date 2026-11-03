<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Strava nur noch auf Freischaltung (Jan, 03.11.2026).
 *
 * Strava hat keine freien Plätze mehr, und die Werbung holt Garmin-Läufer.
 * Neue Athleten sehen Strava deshalb nicht; ein Admin schaltet es pro
 * Athlet frei. Wer schon ein Konto hat, behält es — alle bestehenden
 * Athleten werden freigeschaltet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('strava_allowed')->default(false)->after('activity_source');
        });

        DB::table('users')->update(['strava_allowed' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('strava_allowed');
        });
    }
};
