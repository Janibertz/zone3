<?php
return [
    'setup' => function ($t) { $ctx = $t->athlete(); $ctx['week'] = $t->week($ctx); return $ctx; },
    'message' => 'Verschieb den langen Lauf von Sonntag auf Samstag, Sonntag habe ich keine Zeit.',
    'expect' => function ($t, $ctx) {
        $t->assertNotNull($t->activeOn($ctx['user'], '2026-11-28')->firstWhere('type', 'long_run'), 'kein langer Lauf am Samstag');
        $t->assertNull($t->activeOn($ctx['user'], '2026-11-29')->firstWhere('type', 'long_run'), 'langer Lauf noch am Sonntag');
    },
];
