<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trainingsmodell und Rad/Zwift — die Einstellungen, die der Athlet im
 * Onboarding und im Profil setzt. Die Planlogik dazu folgt getrennt; diese
 * Spalten tragen nur die Entscheidung.
 *
 * Die FTP liegt schon in `ftp` (seit April), sie wird hier nicht doppelt
 * angelegt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->string('training_model', 20)->default('polarized')->after('coach_notes');
            $table->unsignedTinyInteger('running_experience_years')->nullable()->after('training_model');
            $table->boolean('has_lactate_meter')->default(false)->after('running_experience_years');

            $table->boolean('bike_enabled')->default(false)->after('has_lactate_meter');
            $table->string('bike_season', 10)->default('winter')->after('bike_enabled');
            $table->string('bike_purpose', 10)->default('both')->after('bike_season');
            $table->unsignedTinyInteger('bike_max_per_week')->default(2)->after('bike_purpose');
            $table->json('bike_preferred_days')->nullable()->after('bike_max_per_week');
        });
    }

    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'training_model', 'running_experience_years', 'has_lactate_meter',
                'bike_enabled', 'bike_season', 'bike_purpose', 'bike_max_per_week', 'bike_preferred_days',
            ]);
        });
    }
};
