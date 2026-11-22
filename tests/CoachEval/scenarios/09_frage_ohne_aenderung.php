<?php
// Eine Frage ändert nichts, und die Antwort ist eine Antwort (06.11.2026:
// englische Überlegung im Chat).
return [
    'setup' => function ($t) { $ctx = $t->athlete(); $ctx['week'] = $t->week($ctx); return $ctx; },
    'message' => 'Warum steht am Mittwoch ein Intervall?',
    'expect' => function ($t, $ctx) {
        $t->assertSame(0, (int) $ctx['turn']->changes, 'Frage hat etwas geändert');
        $t->assertGreaterThan(40, mb_strlen($ctx['reply']->content));
    },
];
