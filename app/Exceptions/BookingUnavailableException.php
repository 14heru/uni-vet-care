<?php

namespace App\Exceptions;

use RuntimeException;

class BookingUnavailableException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('The selected appointment slot is no longer available.');
    }
}
