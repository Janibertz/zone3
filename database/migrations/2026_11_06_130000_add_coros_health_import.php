<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COROS liefert Erholungswerte (06.11.2026). Gelesen wird nur mit
 * Einwilligung (Bedingung von COROS) — `ai_consent_at` an der Verbindung wie
 * bei Garmin. Die Werte landen in `garmin_daily_metrics`, damit Dashboard,
 * Plan und Tagesform sie ohne Umbau zeigen; `source` sagt, wer die Zeile
 * geschrieben hat, damit Trennen genau die COROS-Werte loescht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coros_connections', function (Blueprint $table) {
            $table->timestamp('ai_consent_at')->nullable()->after('workout_sync');
            $table->timestamp('health_synced_at')->nullable()->after('ai_consent_at');
        });

        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->string('source', 10)->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('garmin_daily_metrics', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('coros_connections', fn (Blueprint $table) => $table->dropColumn(['ai_consent_at', 'health_synced_at']));
    }
};
