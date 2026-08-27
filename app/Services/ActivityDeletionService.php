<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\IgnoredStravaActivity;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use Illuminate\Support\Facades\DB;

/**
 * Eine Aktivität löschen — und alles zurückdrehen, was ihr Import angerichtet hat.
 *
 * Ein blosses `$activity->delete()` genügt nicht:
 *
 *   · `training_sessions.activity_id` ist `nullOnDelete`. Eine geplante
 *     Einheit, die dieser Import abgehakt hat, bliebe damit auf
 *     „abgeschlossen" stehen — mit den gelaufenen Werten, aber ohne die
 *     Aktivität, aus der sie stammen. Der Plan behauptete dann etwas, wofür
 *     es keinen Beleg mehr gibt.
 *
 *   · Der manuelle Strava-Abgleich legt sie beim nächsten Lauf wieder an.
 *
 * Was der Import angelegt hat, verschwindet mit ihm. Was er nur überschrieben
 * hat, wird aus dem Schnappschuss wiederhergestellt — genau dafür gibt es ihn.
 */
class ActivityDeletionService
{
    /**
     * @return array{sessions_deleted: int, sessions_restored: int}
     */
    public function delete(Activity $activity): array
    {
        $userId   = $activity->user_id;
        $stravaId = $activity->strava_id;

        $deleted  = 0;
        $restored = 0;

        DB::transaction(function () use ($activity, $userId, $stravaId, &$deleted, &$restored) {
            foreach (TrainingSession::where('activity_id', $activity->id)->get() as $session) {
                // Die Einheit gab es nur, weil der Import sie angelegt hat.
                if ($session->was_unplanned) {
                    $session->delete();
                    $deleted++;
                    continue;
                }

                $this->restore($session);
                $restored++;
            }

            // Die Renn-Analyse verweist auf die Aktivität; ohne sie ist der
            // Text nicht mehr belegt.
            TrainingPlan::where('race_analysis_activity_id', $activity->id)->update([
                'race_analysis_text'        => null,
                'race_analysis_activity_id' => null,
            ]);

            // Bestleistungen haengen per cascadeOnDelete an der Aktivitaet und
            // gehen mit ihr; der Grabstein muss sie ueberleben.
            if ($stravaId) {
                IgnoredStravaActivity::firstOrCreate([
                    'user_id'   => $userId,
                    'strava_id' => $stravaId,
                ]);
            }

            $activity->delete();
        });

        return ['sessions_deleted' => $deleted, 'sessions_restored' => $restored];
    }

    /**
     * Eine geplante Einheit auf ihren Zustand vor dem Import zurücksetzen.
     *
     * Der Schnappschuss trägt, was der Coach vorgesehen hatte. Fehlt er —
     * bei Einheiten aus der Zeit vor seiner Einführung —, bleiben die
     * gelaufenen Zahlen stehen; sie zu erfinden wäre schlimmer als sie
     * ungenau zu lassen. Der Status geht in jedem Fall auf „geplant".
     */
    private function restore(TrainingSession $session): void
    {
        $snapshot = $session->planned_snapshot;

        $session->update([
            'status'           => 'planned',
            'activity_id'      => null,
            'planned_snapshot' => null,
            'was_unplanned'    => false,

            'type'         => $snapshot['type']         ?? $session->type,
            'title'        => $snapshot['title']        ?? $session->title,
            'distance_km'  => $snapshot['distance_km']  ?? $session->distance_km,
            'duration_min' => $snapshot['duration_min'] ?? $session->duration_min,
            'pace_target'  => $snapshot['pace_target']  ?? $session->pace_target,
            'zone'         => $snapshot['zone']         ?? $session->zone,
            'intensity'    => $snapshot['intensity']    ?? $session->intensity,

            // Das Review beschrieb einen Lauf, den es nicht mehr gibt.
            'coach_review'     => null,
            'review_question'  => null,
            'review_options'   => null,
            'review_feedback'  => null,
            'reviewed_at'      => null,
            'rating'           => null,
            'effort_perceived' => null,
            'feeling_notes'    => null,
        ]);
    }
}
