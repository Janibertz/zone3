<?php
// 26.11.2026: Gestern „Heute kein Lauf mehr“ → Ruhetag gestern eingetragen.
// Heute „Plane mir Intervalle auf dem Rad ein“ — der Coach griff zum Ruhetag
// von gestern (der Verlauf trug kein Datum) und sagte „Heute ist vorbei“.
// Erwartet: eine Radeinheit heute, gestern bleibt, wie es war.
use App\Models\CoachMessage;

return [
    'setup' => function ($t) {
        $ctx = $t->athlete([], ['plan_start_date' => '2026-11-30']);
        $ctx['yesterday'] = $t->addSession($ctx, '2026-11-22', 'rest', ['pinned_at' => '2026-11-22 16:23:00']);
        $q = CoachMessage::create(['user_id' => $ctx['user']->id, 'role' => 'user', 'content' => 'Heute kein Lauf mehr.', 'channel' => 'telegram']);
        $q->forceFill(['created_at' => '2026-11-22 16:23:00'])->save();
        $a = CoachMessage::create(['user_id' => $ctx['user']->id, 'role' => 'assistant', 'content' => 'Richtig so. Heute ist Ruhetag.', 'channel' => 'telegram', 'reply_to_id' => $q->id,
            'actions' => [['type' => 'plan_change', 'label' => 'Ruhetag (Sonntag, 22.11.)', 'change_id' => 0]]]);
        $a->forceFill(['created_at' => '2026-11-22 16:23:10'])->save();
        return $ctx;
    },
    'message' => 'Ich habe aber Lust auf Intervalle auf dem Rad oder einen Tempoblock. Plane das für mich ein.',
    'expect' => function ($t, $ctx) {
        $bike = $t->activeOn($ctx['user'], '2026-11-23')->first(fn ($s) => in_array($s->sport_type, ['VirtualRide', 'Ride'], true));
        $t->assertNotNull($bike, 'keine Radeinheit heute eingetragen');
        $t->assertSame('rest', $ctx['yesterday']->fresh()->type, 'Ruhetag von gestern verändert');
    },
];
