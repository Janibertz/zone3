<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einheiten, die der Athlet selbst gesetzt hat, ueberleben eine
 * Neuberechnung.
 *
 * Beide Plan-Jobs raeumen vor dem Schreiben auf:
 *
 *   TrainingSession::whereIn('training_plan_id', $ids)
 *       ->where('status', 'planned')->delete();
 *
 * Damit verschwand auch, was der Athlet im Chat ausdruecklich bestellt
 * hatte — "ich moechte am Sonntag 25 km laufen" wurde beim naechsten
 * Durchlauf still durch den Wert aus der Leiter ersetzt. Was der Athlet
 * sagt, wiegt aber mehr als das, was das Modell sich als Naechstes
 * ausdenkt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('was_unplanned');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn('pinned_at');
        });
    }
};
