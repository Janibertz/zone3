<?php

namespace App\Http\Middleware;

use App\Services\Observability\Collector;
use App\Services\Observability\Recorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Misst die Anfrage — und schreibt erst, wenn sie beantwortet ist.
 *
 * `handle()` tut absichtlich nichts. Die Zeit kommt aus `LARAVEL_START`, die
 * Queries hat der Sammler schon ueber `DB::listen` mitgezaehlt, und der
 * einzige Schreibvorgang passiert in `terminate()` — nach der Antwort.
 *
 * Das ist der ganze Trick: der Webprozess ist single-threaded, und eine
 * Messung, die waehrend der Anfrage schreibt, verlangsamt genau das, was sie
 * beobachten soll.
 */
class RecordPerformance
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Collector $collector,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Die Middleware steht ganz vorn in der Kette, deshalb geht durch das
        // Zuruecksetzen nichts verloren — Session und Auth zaehlen mit.
        //
        // Ohne das kaeme die Startzeit aus `LARAVEL_START`. In Produktion
        // waere das richtig (ein Prozess je Anfrage), im Test aber die
        // Laufzeit von PHPUnit, und jede Anfrage saehe aus wie eine langsame.
        $this->collector->reset();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->recorder->request(
            $request,
            $response->getStatusCode(),
            $this->collector->elapsedMs(),
        );
    }
}
