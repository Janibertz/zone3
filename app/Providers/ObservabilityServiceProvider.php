<?php

namespace App\Providers;

use App\Services\Observability\Collector;
use App\Services\Observability\Recorder;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Haengt die Messung an die vier Achsen, auf denen Zone3 kaputtgeht.
 *
 * Die Listener werden IMMER registriert, auch wenn die Aufzeichnung
 * abgeschaltet ist — geprueft wird erst im `Recorder`, kurz vor dem
 * Schreiben. Sonst waere der Schalter in der Konfiguration einer, der nur
 * beim Neustart wirkt, und das ist genau dann unpraktisch, wenn man ihn
 * braucht.
 *
 * Was hier NICHT steht: eine Ueberwachung des Schedulers durch den
 * Scheduler. `perf:watch` laeuft selbst als geplantes Kommando und kann
 * seinen eigenen Ausfall nicht melden. Diese eine Frage beantwortet
 * `SystemHealth` beim Seitenaufruf.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons, weil die Middleware fuer `terminate()` erneut aus dem
        // Container aufgeloest wird — mit zwei Instanzen waere der Zaehler
        // dort leer.
        $this->app->singleton(Collector::class);
        $this->app->singleton(Recorder::class);
    }

    public function boot(): void
    {
        $this->watchQueries();
        $this->watchJobs();
        $this->watchCommands();
        $this->watchOutgoingCalls();
    }

    /** Jede Query zaehlt mit; gespeichert werden nur die langsamen. */
    private function watchQueries(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            $this->app->make(Collector::class)->query($query->sql, $query->time);
        });
    }

    /**
     * Queue-Jobs mit Dauer, Ausgang und ihrer eigenen Query-Zahl.
     *
     * Ein Job, der dreissig Sekunden braucht, tut das oft nicht wegen
     * OpenAI — das sieht man erst, wenn die Queries danebenstehen.
     */
    private function watchJobs(): void
    {
        $started = [];

        Event::listen(function (JobProcessing $event) use (&$started): void {
            $started[] = microtime(true);
            $this->app->make(Collector::class)->reset();
        });

        Event::listen(function (JobProcessed $event) use (&$started): void {
            $this->app->make(Recorder::class)->job(
                $event->job->resolveName(),
                $event->job->getQueue() ?: 'default',
                $this->elapsed($started),
            );
        });

        Event::listen(function (JobFailed $event) use (&$started): void {
            $this->app->make(Recorder::class)->job(
                $event->job->resolveName(),
                $event->job->getQueue() ?: 'default',
                $this->elapsed($started),
                $event->exception->getMessage(),
            );
        });
    }

    /**
     * Kommandos — fortgeschrieben, nicht angehaengt.
     *
     * `schedule:run` laeuft im Minutentakt und ist damit die ehrlichste
     * Antwort auf „lebt der Scheduler ueberhaupt".
     */
    private function watchCommands(): void
    {
        $started = [];

        Event::listen(function (CommandStarting $event) use (&$started): void {
            if (! $event->command) {
                return;
            }

            $started[] = microtime(true);
            $this->app->make(Collector::class)->reset();
        });

        Event::listen(function (CommandFinished $event) use (&$started): void {
            if (! $event->command) {
                return;
            }

            $this->app->make(Recorder::class)->command(
                $event->command,
                $this->elapsed($started),
                $event->exitCode,
            );
        });
    }

    /**
     * Strava, OpenAI, fit-service, Wetter.
     *
     * Beantwortet die Frage, die beim Webhook-Ausfall offen blieb: liegt es
     * an uns oder am fremden Dienst. Ein Stapel reicht zum Messen, weil
     * Zone3 nirgends `Http::pool` benutzt und die Aufrufe damit
     * nacheinander laufen.
     */
    private function watchOutgoingCalls(): void
    {
        Event::listen(function (RequestSending $event): void {
            $this->app->make(Collector::class)->outgoingStarted();
        });

        Event::listen(function (ResponseReceived $event): void {
            $collector = $this->app->make(Collector::class);

            $this->app->make(Recorder::class)->outgoing(
                $event->request->url(),
                $event->response->status(),
                $collector->outgoingFinished(),
            );
        });

        Event::listen(function (ConnectionFailed $event): void {
            $collector = $this->app->make(Collector::class);

            $this->app->make(Recorder::class)->outgoing(
                $event->request->url(),
                null,
                $collector->outgoingFinished(),
                failed: true,
            );
        });
    }

    /**
     * Dauer seit dem zuletzt begonnenen Vorgang.
     *
     * Ein Stapel statt einer Zuordnung ueber Job-IDs: unter `sync` hat ein
     * Job gar keine ID, und geschachtelte Dispatches kommen in umgekehrter
     * Reihenfolge zurueck — genau das, was ein Stapel abbildet.
     *
     * @param  list<float>  $started
     */
    private function elapsed(array &$started): float
    {
        $start = array_pop($started);

        return $start ? (microtime(true) - $start) * 1000 : 0.0;
    }
}
