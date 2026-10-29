<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Hügel erreichbar" — ohne Hang plant Zone3 keine Bergsprints und
 * Bergintervalle (RunWorkouts::HILL_VARIANTS). Standard an.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->boolean('hills_available')->default(true)->after('has_lactate_meter');
        });
    }

    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dropColumn('hills_available');
        });
    }
};
