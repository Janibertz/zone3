<?php
// 06.11.2026: „30 min locker auf Zwift“ wurde ein easy_run mit Laufpace.
return [
    'setup' => function ($t) { $ctx = $t->athlete(); $ctx['week'] = $t->week($ctx); return $ctx; },
    'message' => 'Am Samstag möchte ich 30 Minuten locker auf Zwift fahren',
    'expect' => function ($t, $ctx) {
        $sat  = $t->activeOn($ctx['user'], '2026-11-28');
        $bike = $sat->first(fn ($s) => in_array($s->sport_type, ['VirtualRide', 'Ride'], true));
        $t->assertNotNull($bike, 'keine Radeinheit am Samstag');
        $t->assertSame(0, $sat->whereIn('type', ['easy_run', 'tempo_run', 'interval', 'long_run'])->whereNull('sport_type')->count(), 'Zwift als Lauf angelegt');
    },
];
