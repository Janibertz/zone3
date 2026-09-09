<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PerfAlert;
use App\Models\PerfEvent;
use App\Services\Observability\PerfDigest;
use App\Services\Observability\Watchdog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance und Fehlersuche — die zwei Fragen, die `/admin/system` offen
 * laesst.
 *
 * Die Systemseite beantwortet „laeuft die Maschine": Queues, Failed Jobs,
 * Plan-Luecken. Was sie nicht beantwortet, ist „warum ist die Seite
 * langsam" und „lief die Hintergrundarbeit ueberhaupt". Beides hat schon
 * Tage gekostet.
 *
 * Die Zahlen kommen aus `PerfDigest`, die Befunde aus `Watchdog` — derselbe
 * Waechter, der alle 15 Minuten laeuft und benachrichtigt. Eine zweite
 * Beurteilung derselben Daten waere genau die Sorte doppelter Wahrheit, die
 * dieses Projekt schon zweimal teuer bezahlt hat.
 */
class AdminPerformanceController extends Controller
{
    public function index(Request $request, PerfDigest $digest, Watchdog $watchdog): Response
    {
        $hours = $this->window($request);

        return Inertia::render('Admin/Performance/Index', [
            'window'      => $hours,
            'windows'     => PerfDigest::WINDOWS,
            'summary'     => $digest->summary($hours),
            'routes'      => $digest->routes($hours),
            'slowQueries' => $digest->slowQueries($hours),
            'jobs'        => $digest->jobs($hours),
            'outgoing'    => $digest->outgoing($hours),
            'commands'    => $digest->commands(),
            'slowest'     => $digest->slowest($hours),

            // Dieselben Befunde, die der Waechter meldet — nur ohne
            // Nebenwirkung, damit ein Seitenaufruf keine Push-Nachricht
            // ausloest und keine Abklingzeit verbraucht.
            'findings'    => $watchdog->findings(),
            'openAlerts'  => PerfAlert::whereNull('resolved_at')
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (PerfAlert $a) => [
                    'key'         => $a->key,
                    'message'     => $a->message,
                    'occurrences' => $a->occurrences,
                    'notified_at' => $a->last_notified_at?->toIso8601String(),
                ])
                ->all(),

            'thresholds'  => [
                'slow_query_ms'   => (int) config('observability.slow_query_ms'),
                'slow_request_ms' => (int) config('observability.slow_request_ms'),
                'many_queries'    => (int) config('observability.many_queries'),
            ],
        ]);
    }

    /**
     * Den Waechter von Hand laufen lassen — und dabei wirklich
     * benachrichtigen.
     *
     * Der einzige Weg zu pruefen, ob die Push-Zustellung funktioniert, bevor
     * man sich darauf verlaesst. Die Abklingzeit gilt auch hier: zweimal
     * hintereinander gedrueckt kommt nur eine Nachricht.
     */
    public function check(Watchdog $watchdog): RedirectResponse
    {
        $findings = $watchdog->run();
        $sent     = count(array_filter(array_column($findings, 'notified')));

        $message = $findings === []
            ? 'Keine Auffälligkeiten.'
            : count($findings) . ' Auffälligkeit(en), ' . $sent . ' gemeldet.';

        return back()->with('success', $message);
    }

    /**
     * Alle Messwerte verwerfen.
     *
     * Nach einer Umstellung sind die alten Zahlen irrefuehrend — sie
     * beschreiben Code, den es nicht mehr gibt. Die Kommando-Zustaende
     * bleiben stehen: „wann lief `strava:sync` zuletzt" ist eine Frage, auf
     * die auch direkt nach dem Aufraeumen eine Antwort gehoert.
     */
    public function flush(): RedirectResponse
    {
        $count = PerfEvent::count();

        PerfEvent::query()->delete();

        Log::info('Messwerte verworfen', ['count' => $count, 'by' => auth()->id()]);

        return back()->with('success', "{$count} Messwert(e) verworfen.");
    }

    /** Nur die angebotenen Zeitraeume, damit niemand 100000 Stunden abfragt. */
    private function window(Request $request): int
    {
        $hours = (int) $request->integer('hours', 24);

        return in_array($hours, PerfDigest::WINDOWS, true) ? $hours : 24;
    }
}
