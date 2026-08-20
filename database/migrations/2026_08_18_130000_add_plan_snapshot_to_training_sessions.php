<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bewahrt den geplanten Zustand einer Einheit auf.
 *
 * Beim Import aus Strava wurden distance_km, duration_min und pace_target
 * mit den tatsaechlichen Werten ueberschrieben — der Plan war danach weg.
 * Das Coach-Review las anschliessend fuer "Geplant" und "Absolviert"
 * dieselben Felder und bekam zwangslaeufig zwei identische Zahlen zu sehen.
 * Eine Abweichung vom Plan konnte es gar nicht bemerken.
 *
 * `was_unplanned` unterscheidet ausserdem eine absolvierte Planeinheit von
 * einem Lauf, den es so nie im Plan gab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->json('planned_snapshot')->nullable()->after('zone');
            $table->boolean('was_unplanned')->default(false)->after('planned_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn(['planned_snapshot', 'was_unplanned']);
        });
    }
};
