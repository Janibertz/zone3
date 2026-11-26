<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der letzte Body-Battery-Wert des Tages mit Uhrzeit (seit 26.11.2026). Die
 * Readiness läuft damit über den Tag mit; Tief und Hoch allein sagen nicht,
 * wo der Akku gerade steht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->unsignedTinyInteger('body_battery_now')->nullable()->after('body_battery_high');
            $table->timestamp('body_battery_at')->nullable()->after('body_battery_now');
        });
    }

    public function down(): void
    {
        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->dropColumn(['body_battery_now', 'body_battery_at']);
        });
    }
};
