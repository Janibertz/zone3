<?php

namespace App\Services\Whoop;

use RuntimeException;

class WhoopException extends RuntimeException
{
    /** WHOOP nimmt den Zugang nicht mehr an (in der WHOOP-App getrennt). */
    public function revoked(): bool
    {
        return in_array($this->getCode(), [400, 401, 403], true);
    }
}
