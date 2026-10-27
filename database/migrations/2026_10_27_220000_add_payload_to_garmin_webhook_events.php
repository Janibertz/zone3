<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Rohdaten eines Health-Pushs.
 *
 * Die Feldnamen der Health API stehen nur in Garmins Spezifikation hinter dem
 * Portal-Login. Was Garmin wirklich schickt, zeigt erst der erste echte Push —
 * und dann soll es nachlesbar sein, statt nur in einer verworfenen Antwort.
 * `garmin:prune-webhook-payloads` leert die Spalte nach 14 Tagen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garmin_webhook_events', function (Blueprint $table) {
            $table->longText('payload')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('garmin_webhook_events', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
};
