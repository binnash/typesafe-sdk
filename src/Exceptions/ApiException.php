<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Exceptions;

use Binnash\Typesafe\Support\Headers;
use Throwable;

/**
 * An unsuccessful HTTP response from the API.
 *
 * The message is derived from the response body when possible, prefixed with
 * the status code.
 */
class ApiException extends TypeSafeException
{
    /** Longest raw body fragment included in a derived message. */
    private const MAX_RAW_BODY_IN_MESSAGE = 200;

    /** Request ID reported by the API, when the response carried one. */
    public readonly ?string $requestId;

    /**
     * @param  int  $status  HTTP response status code.
     * @param  mixed  $body  Parsed JSON, raw response text, or `null` for an empty body.
     * @param  array<string, string|list<string>>  $headers  HTTP response headers.
     * @param  string|null  $message  Overrides the message derived from the body.
     * @param  Throwable|null  $previous  The underlying failure, when any.
     */
    public function __construct(
        public readonly int $status,
        public readonly mixed $body = null,
        public readonly array $headers = [],
        ?string $message = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? self::describe($status, $body), $status, $previous);

        $this->requestId = Headers::requestId($headers);
    }

    /**
     * Create the error subclass matching an HTTP status code.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public static function fromResponse(
        int $status,
        mixed $body = null,
        array $headers = [],
        ?string $message = null,
    ): self {
        return match ($status) {
            400 => new BadRequestException($status, $body, $headers, $message),
            401 => new AuthenticationException($status, $body, $headers, $message),
            403 => new PermissionDeniedException($status, $body, $headers, $message),
            404 => new NotFoundException($status, $body, $headers, $message),
            422 => new UnprocessableEntityException($status, $body, $headers, $message),
            429 => new RateLimitException($status, $body, $headers, $message),
            default => $status >= 500
                ? new InternalServerException($status, $body, $headers, $message)
                : new self($status, $body, $headers, $message),
        };
    }

    /**
     * Extract a human-readable message from a text, error, or validation body.
     */
    public static function extractMessage(mixed $body): ?string
    {
        if (is_string($body)) {
            return $body === '' ? null : $body;
        }

        if (! is_array($body)) {
            return null;
        }

        $error = $body['error'] ?? null;

        if (is_string($error)) {
            return $error;
        }

        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        if (is_string($body['message'] ?? null)) {
            return $body['message'];
        }

        $detail = $body['detail'] ?? null;

        if (is_string($detail)) {
            return $detail;
        }

        if (is_array($detail)) {
            if (is_string($detail['message'] ?? null)) {
                return $detail['message'];
            }

            return self::describeValidationErrors($detail);
        }

        return null;
    }

    /**
     * Build the message for a response, falling back to a truncated raw body.
     */
    private static function describe(int $status, mixed $body): string
    {
        $detail = self::extractMessage($body);

        if ($detail !== null && $detail !== '') {
            return $status.' '.$detail;
        }

        if ($body === null || $body === '') {
            return $status.' status code (no body)';
        }

        $raw = is_string($body) ? $body : (string) json_encode($body);

        return $status.' '.(mb_strlen($raw) > self::MAX_RAW_BODY_IN_MESSAGE
            ? mb_substr($raw, 0, self::MAX_RAW_BODY_IN_MESSAGE).'…'
            : $raw);
    }

    /**
     * Format validation errors as semicolon-separated `path: message` entries.
     *
     * @param  array<array-key, mixed>  $errors
     */
    private static function describeValidationErrors(array $errors): ?string
    {
        $parts = [];

        foreach ($errors as $error) {
            if (! is_array($error) || ! is_string($error['msg'] ?? null)) {
                continue;
            }

            $location = $error['loc'] ?? null;
            $path = '';

            if (is_array($location)) {
                $segments = array_filter($location, static fn (mixed $segment): bool => $segment !== 'body');
                $path = implode('.', array_map(static fn (mixed $segment): string => (string) $segment, $segments));
            }

            $parts[] = $path === '' ? $error['msg'] : $path.': '.$error['msg'];
        }

        return $parts === [] ? null : implode('; ', $parts);
    }
}
