<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Das Tagesbudget in Euro pro Athlet, im Admin einstellbar.
 *
 * Bis hierher gab es zwei Grenzen fuer den Coach-Chat, aber nur eine davon
 * im Admin: die Anzahl (`ai_daily_limit`). Das Geld (0,30 EUR aus der
 * Umgebung) galt fuer alle gleich — wer das Limit eines Athleten hochsetzte,
 * sah ihn trotzdem am Euro-Budget scheitern. NULL = Vorgabe aus der Umgebung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('ai_daily_budget_eur', 6, 2)->nullable()->after('ai_daily_limit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ai_daily_budget_eur');
        });
    }
};
