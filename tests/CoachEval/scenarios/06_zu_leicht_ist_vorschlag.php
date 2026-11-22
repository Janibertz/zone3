<?php
// 29.10.2026 (Jan): Rückmeldung ist kein Auftrag — eigene Ideen schlägt der Coach vor.
return [
    'setup' => function ($t) {
        $ctx = $t->athlete();
        $ctx['done'] = $t->addSession($ctx, '2026-11-22', 'interval', ['status' => 'completed']);
        $ctx['week'] = $t->week($ctx);
        return $ctx;
    },
    'message' => 'Die Intervalle gestern waren viel zu leicht.',
    'expect' => function ($t, $ctx) {
        $wed = $ctx['week']['wed']->fresh();
        $t->assertSame('interval', $wed->type);
        $t->assertSame(45, (int) $wed->duration_min, 'Mittwoch direkt geändert statt vorgeschlagen');
    },
];
