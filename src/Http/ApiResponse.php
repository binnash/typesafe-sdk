<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Http;

use Binnash\Typesafe\Support\Headers;

/**
 * A successful HTTP response with its decoded body and metadata.
 */
final class ApiResponse
{
    /**
     * @param  int  $status  HTTP response status code.
     * @param  array<string, list<string>>  $headers  HTTP response headers.
     * @param  mixed  $data  Decoded JSON body, raw text, or `null` for an empty body.
     * @param  string|null  $requestId  Value of `x-typesafe-request-id`, when present.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly mixed $data = null,
        public readonly ?string $requestId = null,
    ) {}

    /**
     * Read the first value of a response header.
     */
    public function header(string $name): ?string
    {
        return Headers::first($this->headers, $name);
    }
}
