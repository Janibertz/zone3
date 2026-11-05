<?php

namespace App\Services\Coros;

use App\Models\CorosConnection;

/**
 * Der COROS-Trainingskalender, so wie der Abgleich ihn braucht.
 *
 * Heute steht dahinter MCP (`McpCorosCalendar`). Bewilligt COROS die
 * Partner API, kommt eine zweite Umsetzung dazu — `CorosWorkoutSync` und
 * `CorosWorkoutBuilder` bleiben, wie sie sind. Bewusst ohne Loeschen und
 * Verschieben: MCP kann beides nicht, und der Abgleich ist darauf gebaut.
 */
interface CorosCalendar
{
    /**
     * Ein Workout an einem Tag anlegen.
     *
     * @param  array<string, mixed>  $course  im Format von `CorosWorkoutBuilder`
     * @return string  die ID des Platzes an diesem Tag (`idInPlan`)
     */
    public function create(CorosConnection $connection, string $date, array $course): string;

    /** Das Workout auf einem Platz komplett ersetzen. */
    public function replace(CorosConnection $connection, string $date, string $idInPlan, array $course): void;
}
