<?php
// Stufe 2: „mach das rückgängig“ nach einer Änderung des Coaches nimmt das
// letzte Paket zurück.
return [
    'setup' => function ($t) {
        $ctx = $t->athlete();
        $ctx['run'] = $t->addSession($ctx, '2026-11-24', 'easy_run', ['duration_min' => 45]);
        $result = app(\App\Services\Coach\PlanChange::class)->run($ctx['user'], [['op' => 'remove', 'session_id' => $ctx['run']->id]], 'apply');
        \App\Models\CoachMessage::create(['user_id' => $ctx['user']->id, 'role' => 'user', 'content' => 'Streich den Lauf morgen', 'channel' => 'telegram']);
        $q = \App\Models\CoachMessage::latest('id')->first();
        \App\Models\CoachMessage::create(['user_id' => $ctx['user']->id, 'role' => 'assistant', 'content' => 'Ist gestrichen.', 'channel' => 'telegram', 'reply_to_id' => $q->id,
            'actions' => [['type' => 'plan_change', 'label' => $result['lines'][0], 'change_id' => $result['change_id'], 'undo' => true]]]);
        return $ctx;
    },
    'message' => 'Ach nee, mach das bitte rückgängig, ich laufe doch',
    'expect' => function ($t, $ctx) {
        $t->assertNotNull(\App\Models\TrainingSession::find($ctx['run']->id), 'Lauf nicht zurück');
    },
];
