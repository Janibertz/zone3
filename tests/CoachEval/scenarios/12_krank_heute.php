<?php
// Krank ist krank (25.10.2026): kein Training heute.
return [
    'setup' => function ($t) { $ctx = $t->athlete(); $ctx['week'] = $t->week($ctx); return $ctx; },
    'message' => 'Ich habe heute Fieber und Halsschmerzen.',
    'expect' => function ($t, $ctx) {
        $mon = $ctx['week']['mon']->fresh();
        $t->assertTrue($mon === null || $mon->status !== 'planned' || $mon->type === 'rest', 'Lauf heute steht noch');
    },
];
