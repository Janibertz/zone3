<?php

namespace App\Services\Coros;

use RuntimeException;

/**
 * Ein Fehler von COROS: abgelehnter Token, HTTP-Fehler oder ein Werkzeug,
 * das mit `isError` antwortet. `status` ist der HTTP-Status (0, wenn das
 * Werkzeug selbst den Fehler meldet).
 */
class CorosException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }

    /** Der Token gilt nicht mehr — weitere Aufrufe sind zwecklos. */
    public function unauthorized(): bool
    {
        return in_array($this->status, [401, 403], true);
    }
}
