<?php

namespace App\Services\Polar;

use RuntimeException;

class PolarException extends RuntimeException
{
    /** Polar nimmt den Token nicht mehr an (widerrufen in Polar Flow). */
    public function revoked(): bool
    {
        return in_array($this->getCode(), [401, 403], true);
    }
}
