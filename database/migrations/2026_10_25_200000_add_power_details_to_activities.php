<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalized Power und die Herkunft der Watt.
 *
 * Für den Hinweis „deine FTP wirkt zu niedrig" reicht der Durchschnitt nicht:
 * er zählt Rollphasen und Ampelstopps mit und liegt bei einer Intervallfahrt
 * weit unter dem, was gefahren wurde. Strava liefert `weighted_average_watts`
 * (Normalized Power) mit. `device_watts` sagt, ob die Watt gemessen oder von
 * Strava aus Tempo und Höhenmetern geschätzt sind — eine geschätzte Zahl
 * taugt für keine FTP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedSmallInteger('weighted_average_watts')->nullable()->after('average_watts');
            $table->boolean('device_watts')->nullable()->after('weighted_average_watts');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['weighted_average_watts', 'device_watts']);
        });
    }
};
