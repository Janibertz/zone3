<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VO2max und Fitnessalter aus Garmins User Metrics.
 *
 * Die Lauf-VO2max ist eine dritte Quelle fuer den VDOT (VdotService),
 * Rad-VO2max und Fitnessalter werden nur angezeigt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->decimal('vo2max', 4, 1)->nullable()->after('training_readiness');
            $table->decimal('vo2max_cycling', 4, 1)->nullable()->after('vo2max');
            $table->unsignedTinyInteger('fitness_age')->nullable()->after('vo2max_cycling');
        });
    }

    public function down(): void
    {
        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->dropColumn(['vo2max', 'vo2max_cycling', 'fitness_age']);
        });
    }
};
