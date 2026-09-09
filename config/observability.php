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
    | Zusammenfassung + Ausreisser
    |--------------------------------------------------------------------------
    |
    | Pro Anfrage entsteht EINE Zeile: Dauer, Query-Anzahl, Query-Zeit. Die
    | einzelnen Queries werden nur gespeichert, wenn sie ueber der Schwelle
    | liegen. Anders waeren es vierzig Zeilen pro Seitenaufruf, und die
    | Messung wuerde mehr Last erzeugen als sie erklaert.
    |
    | `many_queries` ist die Grenze, ab der eine Anfrage als N+1-Verdacht
    | markiert wird — nicht als Fehler, sondern als Hinweis, wo man hinsehen
    | sollte.
    |
    */

    'slow_query_ms'   => (int) env('OBSERVABILITY_SLOW_QUERY_MS', 100),
    'slow_request_ms' => (int) env('OBSERVABILITY_SLOW_REQUEST_MS', 1000),
    'many_queries'    => (int) env('OBSERVABILITY_MANY_QUERIES', 60),

    /*
    |--------------------------------------------------------------------------
    | Wie viele Anfragen aufgezeichnet werden
    |--------------------------------------------------------------------------
    |
    | 1.0 = alle. Bei vier Athleten ist das die richtige Zahl; der Regler
    | steht hier fuer den Tag, an dem es mehr werden. Langsame Anfragen und
    | Fehler werden IMMER aufgezeichnet, unabhaengig von der Rate — sonst
    | verschwindet ausgerechnet das, wonach man sucht.
    |
    */

    'sample_rate' => (float) env('OBSERVABILITY_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Was nicht aufgezeichnet wird
    |--------------------------------------------------------------------------
    |
    | `/up` ist der Health-Check von Coolify und schlaegt im Minutentakt auf.
    | Aufgezeichnet wuerde er die Tabelle fuellen und keine einzige Frage
    | beantworten.
    |
    */

    'ignore_paths' => [
        'up',
        'build/*',
        'storage/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Aufbewahrung
    |--------------------------------------------------------------------------
    |
    | `perf:prune` laeuft naechtlich. Ohne das waechst die Tabelle
    | unbegrenzt — dieselbe Falle wie die Logdatei, die niemand rotiert hat.
    |
    */

    'retention_days' => (int) env('OBSERVABILITY_RETENTION_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Wann sich das System meldet
    |--------------------------------------------------------------------------
    |
    | `cooldown_hours` verhindert, dass derselbe Befund alle 15 Minuten
    | erneut auf dem Telefon landet. Eine Warnung, die staendig kommt, ist
    | eine, die niemand mehr liest.
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
        'server_error_burst'    => (int) env('OBSERVABILITY_SERVER_ERROR_BURST', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Erwartete Taktung der Hintergrundarbeit
    |--------------------------------------------------------------------------
    |
    | Minuten, nach denen ein Kommando als ueberfaellig gilt. Das ist die
    | Achse, auf der sechs Tage Import-Ausfall unbemerkt blieben.
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
    ],

];
