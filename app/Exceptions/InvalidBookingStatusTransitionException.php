<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidBookingStatusTransitionException extends RuntimeException
{
    public static function rejected(): self
    {
        return new self('The requested booking status transition is not allowed.');
    }
}
