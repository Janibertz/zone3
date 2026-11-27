<?php
// 27.11.2026: „Heute würde ich 1000 Meter schwimmen gehen“ wurde
// „Schwimmen 45 min“. Erwartet: das Schwimmen kommt zusätzlich zum Lauf,
// und die 1000 m stehen in der Einheit.
return [
    'setup' => function ($t) {
        $ctx = $t->athlete();
        $ctx['run'] = $t->addSession($ctx, '2026-11-23', 'easy_run', ['title' => 'Locker mit Steigerungen', 'duration_min' => 45, 'distance_km' => 8]);
        return $ctx;
    },
    'message' => 'Heute würde ich 1000 Meter schwimmen gehen',
    'expect' => function ($t, $ctx) {
        $t->assertSame('planned', $ctx['run']->fresh()?->status, 'der Lauf von heute ist weg');
        $swim = $t->activeOn($ctx['user'], '2026-11-23')->firstWhere('sport_type', 'Swim');
        $t->assertNotNull($swim, 'kein Schwimmen eingetragen');
        $t->assertStringContainsString('1.000 m', (string) $swim->title, 'die Strecke fehlt in der Schwimmeinheit');
    },
];
