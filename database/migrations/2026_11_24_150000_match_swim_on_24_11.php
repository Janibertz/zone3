<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Einmalig (24.11.2026): Jans Schwimmen landete als ungeplante Einheit
 * neben der geplanten Schwimmeinheit, weil der Abgleich nur Rad kannte.
 * Die geplante Einheit übernimmt die Aktivität, die ungeplante fällt weg.
 * Greift nur, wenn genau dieser Zustand noch besteht.
 */
return new class extends Migration
{
    public function up(): void
    {
        $planned = DB::table('training_sessions')->where('id', 5249)->where('user_id', 1)
            ->where('status', 'planned')->where('sport_type', 'Swim')->first();
        $extra = DB::table('training_sessions')->where('id', 5250)->where('user_id', 1)
            ->where('was_unplanned', true)->where('sport_type', 'Swim')->whereNotNull('activity_id')->first();

        if (! $planned || ! $extra) {
            return;
        }

        DB::transaction(function () use ($planned, $extra) {
            DB::table('training_sessions')->where('id', $extra->id)->delete();
            DB::table('training_sessions')->where('id', $planned->id)->update([
                'planned_snapshot' => $planned->planned_snapshot ?? json_encode([
                    'type'         => $planned->type,
                    'title'        => $planned->title,
                    'duration_min' => $planned->duration_min,
                    'intensity'    => $planned->intensity,
                ]),
                'status'       => 'completed',
                'activity_id'  => $extra->activity_id,
                'duration_min' => $extra->duration_min ?? $planned->duration_min,
                // Was der Coach oder Jan schon zur Aktivität geschrieben hat, wandert mit.
                'coach_review'     => $planned->coach_review ?? $extra->coach_review,
                'review_question'  => $planned->review_question ?? $extra->review_question,
                'review_options'   => $planned->review_options ?? $extra->review_options,
                'reviewed_at'      => $planned->reviewed_at ?? $extra->reviewed_at,
                'rating'           => $planned->rating ?? $extra->rating,
                'effort_perceived' => $planned->effort_perceived ?? $extra->effort_perceived,
                'feeling_notes'    => $planned->feeling_notes ?? $extra->feeling_notes,
                'review_feedback'  => $planned->review_feedback ?? $extra->review_feedback,
                'rating_skipped_at'=> $planned->rating_skipped_at ?? $extra->rating_skipped_at,
                'updated_at'   => now(),
            ]);
        });
    }

    public function down(): void {}
};
