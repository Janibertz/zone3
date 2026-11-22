<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jan, 22.11.2026: „Setze die Kostenbremse mal auf 5 € am Tag." Das
 * Tagesbudget für alle steht seitdem im Admin unter Einstellungen
 * (`app_settings`) und schlägt `OPENAI_BUDGET_DAILY_EUR`. Ein schon
 * eingetragener Wert bleibt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('app_settings')->where('key', 'openai_budget_daily_eur')->exists()) {
            DB::table('app_settings')->insert([
                'key' => 'openai_budget_daily_eur', 'value' => '5', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('app_settings')->where('key', 'openai_budget_daily_eur')->delete();
    }
};
