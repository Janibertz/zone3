<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fremdsportarten aus dem Lauftyp herauslösen.
 *
 * Der Strava-Import kannte zwei Kategorien: Kraft und "alles andere".
 * Alles andere wurde mit `type = 'easy_run'` gespeichert — auch Schwimmen,
 * Radfahren und Yoga. Seit dem Vorgängercommit trägt jede neue Einheit ihre
 * `sport_type`; die bestehenden Datensätze tun das nicht.
 *
 * Diese Migration holt beides nach:
 *   · die Sportart aus der verknüpften Aktivität
 *   · den Typ `cross_training` statt des Lauf-Platzhalters
 *
 * Der Typ ist der eigentliche Schutz. Jede Auswertung, die Laufeinheiten
 * zusammenzählt, filtert auf Lauftypen — mit `easy_run` im Feld rutschte
 * eine Schwimmeinheit auch dann durch, wenn die Sportart danebenstand.
 */
return new class extends Migration
{
    /** Sportarten, die als Laufen zählen. */
    private const RUN_SPORTS = ['Run', 'TrailRun', 'VirtualRun'];

    private const STRENGTH_TYPES = ['strength', 'core', 'mobility'];

    public function up(): void
    {
        DB::table('training_sessions')
            ->join('activities', 'training_sessions.activity_id', '=', 'activities.id')
            ->whereNull('training_sessions.sport_type')
            ->select('training_sessions.id', 'activities.type as sport')
            ->orderBy('training_sessions.id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $update = ['sport_type' => $row->sport];

                    // Der Lauf-Platzhalter weicht nur bei echten Fremdsportarten.
                    // Krafteinheiten behalten ihren Typ — der ist richtig.
                    if (! in_array($row->sport, self::RUN_SPORTS, true)) {
                        $update['type'] = DB::raw(
                            "CASE WHEN type IN ('" . implode("','", self::STRENGTH_TYPES) . "')"
                            . " THEN type ELSE 'cross_training' END"
                        );
                    }

                    DB::table('training_sessions')->where('id', $row->id)->update($update);
                }
            });
    }

    public function down(): void
    {
        DB::table('training_sessions')
            ->where('type', 'cross_training')
            ->update(['type' => 'easy_run']);

        DB::table('training_sessions')->update(['sport_type' => null]);
    }
};
