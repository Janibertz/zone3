<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apple Health aus der iOS-App (08.11.2026, Stufe 2).
 *
 * - `users.apple_health_consent_at`: die Einwilligung, dass die Werte an
 *   Zone3 gehen und in die Prompts dürfen. Ohne sie nimmt der Server
 *   nichts an. Die `*_synced_at` sagen der App, ob sie einmal weit
 *   zurückliest (Rückblick) oder nur die letzten Tage.
 * - `activities.apple_start`: Kennung eines Trainings aus Apple Health,
 *   die Startzeit in Unix-Sekunden wie beim FIT-Upload. Die UUID von
 *   HealthKit wäre eindeutiger, aber `ActivityImportService::store()`
 *   rechnet mit Zahlen, und zwei Trainings zur selben Sekunde gibt es nicht.
 * - `garmin_daily_metrics.spo2` / `respiration_rate`: Blutsauerstoff (%)
 *   und Atemfrequenz (Atemzüge/min) der Nacht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('apple_health_consent_at')->nullable();
            $table->timestamp('apple_health_synced_at')->nullable();
            $table->timestamp('apple_activities_synced_at')->nullable();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('apple_start')->nullable()->after('file_start');
            $table->unique(['user_id', 'apple_start']);
        });

        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->float('spo2')->nullable();
            $table->float('respiration_rate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('garmin_daily_metrics', fn (Blueprint $table) => $table->dropColumn(['spo2', 'respiration_rate']));

        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'apple_start']);
            $table->dropColumn('apple_start');
        });

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'apple_health_consent_at', 'apple_health_synced_at', 'apple_activities_synced_at',
        ]));
    }
};
