<?php
// 13.11.2026: der Coach bestätigte „6 bis 6:30“, die Abschnitte blieben beim VDOT.
return [
    'setup' => function ($t) { $ctx = $t->athlete(); $ctx['week'] = $t->week($ctx); return $ctx; },
    'message' => 'Den lockeren Lauf am Donnerstag will ich bei 6:00 bis 6:30 laufen.',
    'expect' => function ($t, $ctx) {
        $json = json_encode($ctx['week']['thu']->fresh()->steps ?? []);
        $t->assertStringContainsString('6:00', $json, 'Tempo nicht in den Abschnitten');
        $t->assertStringContainsString('6:30', $json, 'Tempo nicht in den Abschnitten');
    },
];
