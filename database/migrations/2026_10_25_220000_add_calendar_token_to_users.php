<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kalender-Abo (iCal). Der Token steht in der Abo-URL und ist der einzige
 * Schutz: Apple Kalender und Google Kalender können sich beim Abonnieren
 * nicht anmelden. NULL = kein Abo; neu erzeugen macht die alte URL ungültig.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique()->after('telegram_linked_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_token']);
            $table->dropColumn('calendar_token');
        });
    }
};
