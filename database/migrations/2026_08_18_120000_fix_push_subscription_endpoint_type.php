<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bringt bestehende Datenbanken auf dieselbe Form wie neue.
 *
 * Die ursprüngliche Migration legte `endpoint` als TEXT an und setzte einen
 * UNIQUE-Index darauf. MySQL lehnt das ab (Fehler 1170: BLOB/TEXT column
 * used in key specification without a key length), weshalb das Anlegen auf
 * jeder frischen Datenbank scheiterte. Umgangen wurde das damals zweimal —
 * erst mit createOrIgnore, dann mit einer hasTable-Abfrage — ohne die
 * Ursache anzufassen.
 *
 * Diese Migration ist absichtlich vorsichtig: Sie tut nichts, wenn die
 * Tabelle fehlt, und bricht ab, bevor sie längere Werte abschneiden würde.
 */
return new class extends Migration
{
    private const MAX = 512;

    public function up(): void
    {
        if (! Schema::hasTable('push_subscriptions')) {
            return;
        }

        // Nichts zu tun, wenn die Spalte bereits die richtige Form hat.
        if (Schema::getColumnType('push_subscriptions', 'endpoint') !== 'text') {
            $this->ensureUniqueIndex();

            return;
        }

        // Ein Endpunkt jenseits der Grenze wuerde beim Wechsel stillschweigend
        // abgeschnitten — dann lieber gar nichts tun und es melden.
        $tooLong = DB::table('push_subscriptions')
            ->whereRaw('LENGTH(endpoint) > ?', [self::MAX])
            ->count();

        if ($tooLong > 0) {
            throw new RuntimeException(
                "push_subscriptions: {$tooLong} Endpunkt(e) laenger als " . self::MAX .
                ' Zeichen. Die Spalte wurde nicht umgestellt, weil dabei Daten verloren gingen.'
            );
        }

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('endpoint', self::MAX)->change();
        });

        $this->ensureUniqueIndex();
    }

    /**
     * Der Index fehlt auf Datenbanken, bei denen das Anlegen seinerzeit
     * scheiterte und die Tabelle anderweitig entstand.
     */
    private function ensureUniqueIndex(): void
    {
        if (Schema::hasIndex('push_subscriptions', 'push_subscriptions_endpoint_unique')) {
            return;
        }

        $duplicates = DB::table('push_subscriptions')
            ->select('endpoint')
            ->groupBy('endpoint')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            throw new RuntimeException(
                "push_subscriptions: {$duplicates} doppelte Endpunkte. Der UNIQUE-Index " .
                'wurde nicht gesetzt — die Duplikate muessen erst bereinigt werden.'
            );
        }

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->unique('endpoint', 'push_subscriptions_endpoint_unique');
        });
    }

    public function down(): void
    {
        // Zurueck zu TEXT waere ein Rueckschritt in einen Zustand, den MySQL
        // gar nicht herstellen kann. Bewusst leer.
    }
};
