<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Exceptions;

use Binnash\Typesafe\Retry\RetryPolicy;
use Throwable;

/**
 * HTTP 429: the rate limit was exceeded.
 */
class RateLimitException extends ApiException
{
    /** Server retry delay in milliseconds, or `null` when absent or invalid. */
    public readonly ?int $retryAfterMs;

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function __construct(
        int $status,
        mixed $body = null,
        array $headers = [],
        ?string $message = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($status, $body, $headers, $message, $previous);

        $this->retryAfterMs = RetryPolicy::parseRetryAfter($headers);
    }
}
