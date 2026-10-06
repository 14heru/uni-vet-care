<?php

namespace App\Exceptions;

use InvalidArgumentException;

class InvalidAuditLogException extends InvalidArgumentException
{
    public static function invalidAction(string $action): self
    {
        return new self('Unsupported audit action.');
    }

    public static function invalidEntityType(string $entityType): self
    {
        return new self('Unsupported audit entity type.');
    }

    public static function invalidEntityId(): self
    {
        return new self('Audit entity ID must be a positive integer or null.');
    }

    public static function invalidMetadata(): self
    {
        return new self('Audit metadata contains unsupported values.');
    }
}
