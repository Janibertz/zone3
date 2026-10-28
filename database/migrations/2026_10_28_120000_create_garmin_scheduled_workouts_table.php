<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was Zone3 in den Garmin-Kalender geschrieben hat (Training API).
 *
 * Eine eigene Tabelle statt Spalten an der Einheit: eine Neuberechnung
 * loescht die Einheiten, und mit ihnen waeren die Garmin-IDs weg — die
 * Workouts blieben dann fuer immer im Kalender des Athleten stehen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garmin_scheduled_workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Faellt die Einheit weg, bleibt die Zeile: genau sie sagt dann,
            // was bei Garmin noch zu loeschen ist.
            $table->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            // Aus dem Workout-Builder gesendet — gehoert nicht zum Plan, der
            // Abgleich fasst es nicht an.
            $table->foreignId('workout_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('manual')->default(false);
            $table->date('date');
            $table->unsignedBigInteger('garmin_workout_id');
            $table->unsignedBigInteger('garmin_schedule_id')->nullable();
            $table->string('payload_hash', 40);
            $table->timestamps();

            $table->index(['user_id', 'date']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Plan automatisch auf die Uhr (Entscheidung von Jan, 28.10.2026).
            $table->boolean('garmin_workout_sync')->default(true)->after('activity_source');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('garmin_workout_sync'));
        Schema::dropIfExists('garmin_scheduled_workouts');
    }
};
