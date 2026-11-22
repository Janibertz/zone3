<?php
// 14.11.2026: dreimal „Heute Ruhetag bitte“, dreimal bestätigt, kein Aufruf.
// Vor dem Planstart steht heute nur der Tagesvorschlag.
return [
    'setup' => function ($t) {
        $ctx = $t->athlete(
            ['recommendation_date' => '2026-11-23', 'today_recommendation' => json_encode(['type' => 'easy_run', 'title' => 'Lockerer Erhalt-Lauf', 'distance_km' => 6, 'duration_min' => 35, 'zone' => 2])],
            ['plan_start_date' => '2026-11-30'],
        );
        return $ctx;
    },
    'message' => 'Heute Ruhetag bitte',
    'expect' => function ($t, $ctx) {
        $rest = $t->activeOn($ctx['user'], '2026-11-23')->firstWhere('type', 'rest');
        $t->assertNotNull($rest, 'kein Ruhetag eingetragen');
    },
];
