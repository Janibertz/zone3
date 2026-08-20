<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wann die Planerstellung begonnen hat.
 *
 * `plan_generating` war ein Schalter ohne Zeitstempel. Wurde der Job hart
 * beendet — ein Deploy startet den Container neu, und `failed()` läuft bei
 * einem SIGKILL nicht — blieb der Schalter für immer stehen. Die Planseite
 * zeigte dann dauerhaft "analysiert deine Daten", und die Schaltfläche war
 * wirkungslos, weil der Controller bei gesetztem Schalter sofort
 * zurückkehrt, ohne einen neuen Job zu starten. Nur derselbe Job hätte den
 * Schalter zurücksetzen können — der aber wurde nie mehr gestartet.
 *
 * Mit dem Zeitstempel lässt sich ein hängengebliebener Lauf erkennen.
 * NULL bei gesetztem Schalter bedeutet: stammt aus der Zeit davor, also
 * hängengeblieben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('plan_generating_at')->nullable()->after('plan_generating');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('plan_generating_at');
        });
    }
};
