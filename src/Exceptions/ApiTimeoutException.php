<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Exceptions;

use Throwable;

/**
 * The whole response did not arrive within the timeout. A kind of connection failure.
 */
class ApiTimeoutException extends ApiConnectionException
{
    /** Configured timeout per attempt, in milliseconds. */
    public readonly int $timeoutMs;

    public function __construct(int $timeoutMs, ?string $message = null, ?Throwable $previous = null)
    {
        parent::__construct($message ?? sprintf('Request timed out after %dms.', $timeoutMs), $previous);

        $this->timeoutMs = $timeoutMs;
    }
}
