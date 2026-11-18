<?php

namespace App\Services\Rouvy;

use RuntimeException;

class RouvyException extends RuntimeException
{
    /** ROUVY nimmt den Zugang nicht mehr an (im Riders Portal getrennt, Refresh-Token tot). */
    public function revoked(): bool
    {
        return in_array($this->getCode(), [401, 403], true);
    }
}
