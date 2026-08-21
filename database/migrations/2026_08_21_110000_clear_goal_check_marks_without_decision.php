<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Räumt Wochenmerker weg, hinter denen keine Entscheidung steht.
 *
 * Der erste Wurf der Zielprüfung setzte `goal_check_week` auch dann, wenn
 * der Athlet nur „Erklär mir das" tippte. Nachfragen ist aber keine
 * Entscheidung: Wer sich erklären ließ und danach nichts tat, sah die Karte
 * für den Rest der Woche nicht mehr — obwohl nichts entschieden war.
 *
 * Der Endpunkt setzt den Merker inzwischen nicht mehr. Die bereits
 * geschriebenen Werte bleiben davon unberührt und müssen weg, sonst
 * schweigt die Prüfung bis zum nächsten Montag.
 *
 * Genau getroffen: `confirm` und `adjust` setzen beide zusätzlich
 * `goal_confirmed_at`. Ein Merker ohne dieses Datum kann deshalb nur aus
 * dem fehlerhaften Aufruf stammen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')
            ->whereNotNull('goal_check_week')
            ->whereNull('goal_confirmed_at')
            ->update(['goal_check_week' => null]);
    }

    public function down(): void
    {
        // Ein weggeräumter Merker lässt sich nicht rekonstruieren — und
        // müsste es auch nicht: er war falsch.
    }
};
