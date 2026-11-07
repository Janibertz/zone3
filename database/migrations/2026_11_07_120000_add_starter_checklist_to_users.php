<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Einsteiger-Checkliste auf „Heute“ (Jan, 07.11.2026).
 *
 * Fast alles liest die Liste aus den Daten. Nur „Plan angesehen“ steht
 * nirgends, und Erledigt-und-Geschlossen sowie das Beta-Abzeichen müssen
 * bleiben, auch wenn später etwas wieder wegfällt (Uhr getrennt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('starter_plan_viewed_at')->nullable();
            $table->timestamp('starter_dismissed_at')->nullable();
            $table->timestamp('beta_badge_at')->nullable();
        });

        // Wer schon einen Plan hat, hat ihn auch angesehen — sonst stünde
        // bei jedem Beta-Tester ein Punkt offen, den er längst erledigt hat.
        DB::table('users')
            ->whereIn('id', DB::table('training_plans')->select('user_id'))
            ->update(['starter_plan_viewed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['starter_plan_viewed_at', 'starter_dismissed_at', 'beta_badge_at']);
        });
    }
};
