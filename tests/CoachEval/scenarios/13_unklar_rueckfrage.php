<?php
// Stufe 2 (22.11.2026, Jan: „lieber Rückfrage“): morgen stehen ein Lauf und
// eine Krafteinheit. „Streich das morgen“ ist unklar — erwartet: nichts
// geändert, der Coach fragt nach.
return [
    'setup' => function ($t) {
        $ctx = $t->athlete();
        $ctx['run'] = $t->addSession($ctx, '2026-11-24', 'easy_run', ['duration_min' => 45]);
        $ctx['kraft'] = $t->addSession($ctx, '2026-11-24', 'strength', ['title' => 'Kraft', 'duration_min' => 30, 'distance_km' => null, 'sort_order' => 2]);
        return $ctx;
    },
    'message' => 'Streich das morgen',
    'expect' => function ($t, $ctx) {
        $t->assertSame('planned', $ctx['run']->fresh()?->status, 'Lauf gestrichen, obwohl unklar');
        $t->assertSame('planned', $ctx['kraft']->fresh()?->status, 'Kraft gestrichen, obwohl unklar');
        $t->assertMatchesRegularExpression('/\?/', $ctx['reply']?->content ?? '', 'Keine Rückfrage');
    },
];
