<?php
// 22.11.2026: Einlaufen per Lap-Taste zugesagt, aber nicht eingetragen.
return [
    'setup' => function ($t) { $ctx = $t->athlete(); $ctx['run'] = $t->addSession($ctx, '2026-11-23', 'long_run'); return $ctx; },
    'message' => 'Mach beim Longrun heute Ein- und Auslaufen offen, ich beende sie per Lap-Taste',
    'expect' => function ($t, $ctx) {
        $steps = collect($ctx['run']->fresh()->steps ?? []);
        $t->assertTrue((bool) ($steps->first()['open'] ?? false), 'Einlaufen nicht offen');
        $t->assertTrue((bool) ($steps->last()['open'] ?? false), 'Auslaufen nicht offen');
    },
];
