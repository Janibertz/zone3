<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polars Nightly Recharge Status (1 sehr schlecht … 6 sehr gut), seit
 * 18.11.2026 (Jan). Steht im Prompt neben den anderen Werten; in die
 * Readiness geht er nicht ein — die rechnet für alle Uhren gleich.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->unsignedTinyInteger('recharge_status')->nullable()->after('recovery_score');
        });
    }

    public function down(): void
    {
        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->dropColumn('recharge_status');
        });
    }
};
