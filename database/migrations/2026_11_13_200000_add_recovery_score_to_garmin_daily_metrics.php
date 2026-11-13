<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHOOPs Recovery (0–100 %). Eigene Spalte, weil sie etwas anderes misst als
 * Garmins Body Battery: Bereitschaft am Morgen statt Energiestand über den Tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->unsignedTinyInteger('recovery_score')->nullable()->after('body_battery_high');
        });
    }

    public function down(): void
    {
        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->dropColumn('recovery_score');
        });
    }
};
