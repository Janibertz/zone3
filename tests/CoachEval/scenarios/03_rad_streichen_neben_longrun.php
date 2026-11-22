<?php
// 22.11.2026 (Jan): Longrun erledigt, zusätzlich 30 min Rad geplant.
// „Rad bitte streichen“: der Coach suchte morgen, später behauptete er,
// das Rad sei schon absolviert. Erwartet: Rad weg, Longrun bleibt.
return [
    'setup' => function ($t) {
        $ctx = $t->athlete();
        $ctx['long'] = $t->addSession($ctx, '2026-11-23', 'long_run', ['status' => 'completed', 'distance_km' => 18.7]);
        $ctx['bike'] = $t->addSession($ctx, '2026-11-23', 'cross_training', ['sport_type' => 'VirtualRide', 'title' => 'Rad locker', 'duration_min' => 30, 'distance_km' => null, 'sort_order' => 2]);
        return $ctx;
    },
    'message' => 'Die 30 min Rad von heute bitte streichen. Laufen reicht',
    'expect' => function ($t, $ctx) {
        $bike = $ctx['bike']->fresh();
        $t->assertTrue($bike === null || $bike->status === 'skipped', 'Radeinheit steht noch');
        $t->assertSame('completed', $ctx['long']->fresh()?->status, 'Longrun angefasst');
    },
];
