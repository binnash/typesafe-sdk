<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Exceptions;

use Throwable;

/**
 * The request or response delivery failed: DNS, TLS, a closed connection, and so on.
 */
class ApiConnectionException extends TypeSafeException
{
    public function __construct(string $message = 'Connection error.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
