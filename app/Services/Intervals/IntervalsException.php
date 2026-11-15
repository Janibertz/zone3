<?php

namespace App\Services\Intervals;

use RuntimeException;

class IntervalsException extends RuntimeException
{
    /** intervals.icu nimmt den Token nicht mehr an (App dort entzogen). */
    public function revoked(): bool
    {
        return in_array($this->getCode(), [401, 403], true);
    }
}
