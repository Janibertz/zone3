<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jan, 22.11.2026: „Lass uns erstmal 1 Euro pro Athlet pro Tag auf alle
 * KI-Aktivitäten setzen." Die Vorgabe je Athlet steht im Admin unter
 * Einstellungen (`app_settings`) und schlägt `OPENAI_BUDGET_USER_DAILY_EUR`.
 * Ein am Athleten eingetragenes Budget bleibt.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'openai_budget_user_daily_eur'],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('app_settings')->where('key', 'openai_budget_user_daily_eur')->delete();
    }
};
