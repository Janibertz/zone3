<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warum ein Konto nicht mehr synchronisiert.
 *
 * Gefunden in der ersten Logzeile, die je in Produktion lesbar war:
 * „Strava-Sync fehlgeschlagen {user_id: 8, HTTP 401}". Der Athlet hatte
 * null Aktivitaeten und nie einen Import — und die Systemseite zeigte ihn
 * trotzdem gruen als „verbunden", weil ein Refresh-Token in der Datenbank
 * steht. Dass Strava ihn ablehnt, sah man nirgends.
 *
 * Ein vorhandener Token und ein gueltiger Token sind zwei verschiedene
 * Dinge. Nur der Abgleich erfaehrt den Unterschied, also haelt er ihn hier
 * fest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strava_accounts', function (Blueprint $table) {
            $table->string('sync_error')->nullable()->after('scope');
            $table->timestamp('sync_error_at')->nullable()->after('sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('strava_accounts', function (Blueprint $table) {
            $table->dropColumn(['sync_error', 'sync_error_at']);
        });
    }
};
