<?php

namespace App\Providers;

use App\Services\Observability\Recorder;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Haengt die Messung an die eine Achse, auf der Zone3 wirklich kaputtgeht.
 *
 * Hier hingen einmal vier: Queries, Jobs, ausgehende Aufrufe und Kommandos.
 * Die ersten drei wurden abgebaut — `DB::listen` auf jeder Query ist bei
 * zwei Athleten ein Preis ohne Gegenwert, und die Antworten standen
 * ohnehin im Log. Was blieb, ist die Achse, auf der sechs Tage
 * Import-Ausfall unbemerkt blieben.
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
    public function boot(): void
    {
        $this->watchCommands();
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
     * Dauer seit dem zuletzt begonnenen Vorgang.
     *
     * Ein Stapel statt einer Zuordnung ueber Namen: geschachtelte Aufrufe
     * (`Artisan::call()` innerhalb eines Kommandos) kommen in umgekehrter
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
