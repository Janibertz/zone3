<?php
// Pro Tag höchstens ein Lauf, auch wenn der Athlet „zusätzlich“ sagt.
return [
    'setup' => function ($t) { $ctx = $t->athlete(); $ctx['week'] = $t->week($ctx); return $ctx; },
    'message' => 'Leg mir am Mittwoch zusätzlich zu den Intervallen noch einen lockeren Lauf an.',
    'expect' => function ($t, $ctx) {
        $runs = $t->activeOn($ctx['user'], '2026-11-25')
            ->whereIn('type', ['easy_run', 'tempo_run', 'interval', 'long_run', 'progressive_run'])->whereNull('sport_type');
        $t->assertSame(1, $runs->count(), 'zwei Läufe am Mittwoch');
    },
];
