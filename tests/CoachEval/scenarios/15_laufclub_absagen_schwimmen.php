<?php
// 24.11.2026: „Ich gehe heute nicht zum Laufclub, ich gehe als Alternative
// schwimmen.“ Vor dem Planstart steht der Laufclub von heute nur als fester
// Termin da; der Coach strich den Laufclub eine Woche später und trug kein
// Schwimmen ein. Erwartet: heute abgesagt, Schwimmen eingetragen, der
// spätere Laufclub bleibt.
return [
    'setup' => function ($t) {
        $day = ['available' => true, 'duration_min' => 60];
        $ctx = $t->athlete(['weekly_availability' => [
            'monday' => $day + ['fixed' => ['type' => 'interval', 'label' => 'KRAFT Laufclub']],
            'tuesday' => $day, 'wednesday' => $day, 'thursday' => $day, 'friday' => $day, 'saturday' => $day, 'sunday' => $day,
        ]], ['plan_start_date' => '2026-11-30']);
        $ctx['club'] = $t->addSession($ctx, '2026-11-30', 'interval', ['title' => 'KRAFT Laufclub', 'duration_min' => 60, 'distance_km' => 10.7]);
        return $ctx;
    },
    'message' => 'Ich gehe heute nicht zum Laufclub ich gehe als Alternative schwimmen.',
    'expect' => function ($t, $ctx) {
        $t->assertSame('planned', $ctx['club']->fresh()?->status, 'Laufclub am 30.11. gestrichen statt heute');
        $t->assertNull(\App\Services\Plan\FixedAppointment::forUser($ctx['user']->fresh(), \Carbon\CarbonImmutable::parse('2026-11-23')), 'Laufclub heute nicht abgesagt');
        $swim = $t->activeOn($ctx['user'], '2026-11-23')->firstWhere('sport_type', 'Swim');
        $t->assertNotNull($swim, 'kein Schwimmen eingetragen');
    },
];
