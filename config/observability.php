<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aufzeichnung an oder aus
    |--------------------------------------------------------------------------
    |
    | Ein Schalter, der alles stilllegt. Wenn die Messung selbst je zum
    | Problem wird, muss sie sich abstellen lassen, ohne dass jemand Code
    | anfassen muss.
    |
    */

    'enabled' => env('OBSERVABILITY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Wann sich das System meldet
    |--------------------------------------------------------------------------
    |
    | `cooldown_hours` verhindert, dass derselbe Befund bei jedem Lauf erneut
    | auf dem Telefon landet. Eine Warnung, die staendig kommt, ist eine, die
    | niemand mehr liest.
    |
    | `webhook_silence_hours` ist bewusst lang und bewusst als Frage
    | formuliert: dass 48 Stunden kein Strava-Webhook kam, kann heissen, dass
    | niemand gelaufen ist.
    |
    */

    'alerts' => [
        'cooldown_hours'        => (int) env('OBSERVABILITY_ALERT_COOLDOWN_HOURS', 6),
        'queue_stalled_minutes' => (int) env('OBSERVABILITY_QUEUE_STALLED_MINUTES', 15),
        'webhook_silence_hours' => (int) env('OBSERVABILITY_WEBHOOK_SILENCE_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Erwartete Taktung der Hintergrundarbeit
    |--------------------------------------------------------------------------
    |
    | Minuten, nach denen ein Kommando als ueberfaellig gilt. Das ist die
    | Achse, auf der sechs Tage Import-Ausfall unbemerkt blieben — und der
    | Grund, warum von der Selbstbeobachtung ueberhaupt etwas stehen blieb.
    |
    | `schedule:run` steht hier bewusst mit drin, obwohl der Watchdog seinen
    | eigenen Ausfall nicht melden kann — er laeuft ja selbst im Scheduler.
    | Diese eine Zeile beantwortet `SystemHealth`, und sie steht damit auf
    | dem Dashboard statt in einer Push-Nachricht, die nie ankaeme.
    |
    */

    'expected_every_minutes' => [
        'schedule:run'             => 10,
        'strava:sync'              => 45,
        'push:wellbeing-reminders' => 30,
        'garmin:sync-health'       => 1560,
        'garmin:refresh-tokens'    => 1560,
        'garmin:prune-webhook-payloads' => 1560,
        'garmin:sync-workouts'     => 150,
    ],

];
