<?php
// 25.10.2026: OP und „zwei Wochen Laufpause“ wurden nur eine Notiz.
return [
    'setup' => function ($t) { $ctx = $t->athlete(); $ctx['week'] = $t->week($ctx); return $ctx; },
    'message' => 'Ich hatte heute eine Weisheitszahn-OP und laufe die nächsten zwei Wochen nicht.',
    'expect' => function ($t, $ctx) {
        $until = $ctx['user']->runnerProfile->fresh()->training_paused_until;
        $t->assertNotNull($until, 'keine Pause gesetzt');
        $t->assertTrue($until->gte(\Carbon\Carbon::parse('2026-12-04')), 'Pause zu kurz: ' . $until->toDateString());
        $t->assertNotSame('planned', $ctx['week']['wed']->fresh()?->status, 'Intervall am Mittwoch steht noch');
    },
];
