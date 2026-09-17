<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Retry;

use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Support\Headers;

/**
 * Retry settings for failed attempts, with capped exponential backoff.
 *
 * Unset values inherit the SDK defaults; construct a policy with the
 * constructor's named arguments to override individual settings.
 */
final class RetryPolicy
{
    /** Maximum retries after the initial attempt; `0` disables retries. */
    public const DEFAULT_MAX_RETRIES = 2;

    /** First backoff delay in milliseconds, doubled on each subsequent attempt. */
    public const DEFAULT_BACKOFF_INITIAL_MS = 500;

    /** Upper bound for a single backoff delay in milliseconds. */
    public const DEFAULT_BACKOFF_MAX_MS = 5_000;

    /** Fraction of each backoff delay randomly subtracted, from 0 to 1. */
    public const DEFAULT_BACKOFF_JITTER = 0.25;

    /** Maximum honored server retry delay in milliseconds; longer delays use backoff. */
    public const DEFAULT_MAX_RETRY_AFTER_MS = 60_000;

    /**
     * HTTP status codes to retry, keyed for lookup.
     *
     * @var array<int, true>
     */
    private readonly array $retryableStatuses;

    /**
     * @param  int  $maxRetries  Maximum retries after the initial attempt.
     * @param  int  $backoffInitialMs  First backoff delay in milliseconds.
     * @param  int  $backoffMaxMs  Maximum backoff delay in milliseconds.
     * @param  float  $backoffJitter  Fraction of each delay randomly subtracted, from 0 to 1.
     * @param  list<int>|null  $httpStatuses  Status codes to retry; defaults to 408, 429, and 500-599.
     * @param  bool  $respectRetryAfter  Honor `retry-after-ms` and `Retry-After` response headers.
     * @param  int  $maxRetryAfterMs  Longest server retry delay to honor in milliseconds.
     * @param  bool  $retryConnectionErrors  Retry connection failures.
     * @param  bool  $retryTimeoutErrors  Retry attempts that exceeded the timeout.
     *
     * @throws TypeSafeException When any setting is outside its accepted range.
     */
    public function __construct(
        public readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        public readonly int $backoffInitialMs = self::DEFAULT_BACKOFF_INITIAL_MS,
        public readonly int $backoffMaxMs = self::DEFAULT_BACKOFF_MAX_MS,
        public readonly float $backoffJitter = self::DEFAULT_BACKOFF_JITTER,
        ?array $httpStatuses = null,
        public readonly bool $respectRetryAfter = true,
        public readonly int $maxRetryAfterMs = self::DEFAULT_MAX_RETRY_AFTER_MS,
        public readonly bool $retryConnectionErrors = true,
        public readonly bool $retryTimeoutErrors = true,
    ) {
        $this->assertNonNegativeInteger('maxRetries', $maxRetries);
        $this->assertNonNegativeInteger('backoffInitialMs', $backoffInitialMs);
        $this->assertNonNegativeInteger('backoffMaxMs', $backoffMaxMs);
        $this->assertNonNegativeInteger('maxRetryAfterMs', $maxRetryAfterMs);

        if (! is_finite($backoffJitter) || $backoffJitter < 0 || $backoffJitter > 1) {
            throw new TypeSafeException(sprintf(
                '`backoffJitter` must be between 0 and 1, got %s.',
                (string) $backoffJitter,
            ));
        }

        $retryableStatuses = [];

        foreach ($httpStatuses ?? self::defaultStatuses() as $status) {
            if ($status < 100 || $status > 999) {
                throw new TypeSafeException(sprintf(
                    '`httpStatuses` must contain HTTP status codes, got %s.',
                    (string) $status,
                ));
            }

            $retryableStatuses[$status] = true;
        }

        $this->retryableStatuses = $retryableStatuses;
    }

    /**
     * A policy with every SDK default applied.
     */
    public static function default(): self
    {
        return new self;
    }

    /**
     * Whether the policy retries an HTTP status code.
     */
    public function isRetryableStatus(int $status): bool
    {
        return isset($this->retryableStatuses[$status]);
    }

    /**
     * Status codes the policy retries, in ascending order.
     *
     * @return list<int>
     */
    public function statuses(): array
    {
        $statuses = array_keys($this->retryableStatuses);
        sort($statuses);

        return $statuses;
    }

    /**
     * Parse `retry-after-ms` or `Retry-After` into milliseconds, preferring `retry-after-ms`.
     *
     * Returns `null` when neither header holds a valid delay.
     *
     * @param  array<string, string|list<string>>  $headers  Response headers.
     * @param  int|null  $nowMs  Current time in milliseconds, for HTTP-date values.
     */
    public static function parseRetryAfter(array $headers, ?int $nowMs = null): ?int
    {
        $retryAfterMs = Headers::first($headers, 'retry-after-ms');

        if ($retryAfterMs !== null && is_numeric($retryAfterMs)) {
            $milliseconds = (float) $retryAfterMs;

            if (is_finite($milliseconds) && $milliseconds >= 0) {
                return (int) round($milliseconds);
            }
        }

        $raw = Headers::first($headers, 'retry-after');

        if ($raw === null) {
            return null;
        }

        if (is_numeric($raw)) {
            $seconds = (float) $raw;

            return $seconds >= 0 ? (int) round($seconds * 1000) : null;
        }

        $timestamp = strtotime($raw);

        if ($timestamp === false) {
            return null;
        }

        $nowMs ??= (int) round(microtime(true) * 1000);

        return max(0, $timestamp * 1000 - $nowMs);
    }

    /**
     * Delay in milliseconds for a zero-based retry attempt.
     *
     * An allowed server delay takes precedence; otherwise the delay is capped
     * exponential backoff with jitter. Pass `$random` to make it deterministic.
     *
     * @param  int  $attempt  Zero-based retry attempt number.
     * @param  array<string, string|list<string>>|null  $headers  Response headers, when available.
     * @param  float|null  $random  Value between 0 and 1; defaults to a random value.
     */
    public function calculateDelayMs(int $attempt, ?array $headers = null, ?float $random = null): int
    {
        if ($this->respectRetryAfter && $headers !== null) {
            $retryAfter = self::parseRetryAfter($headers);

            if ($retryAfter !== null && $retryAfter <= $this->maxRetryAfterMs) {
                return $retryAfter;
            }
        }

        $exponential = (float) $this->backoffInitialMs * (2 ** $attempt);
        $exponential = min($exponential, (float) $this->backoffMaxMs);
        $random ??= mt_rand() / mt_getrandmax();

        return (int) round($exponential * (1 - $random * $this->backoffJitter));
    }

    /**
     * Default retryable status codes: 408, 429, and 500-599.
     *
     * @return list<int>
     */
    private static function defaultStatuses(): array
    {
        return [408, 429, ...range(500, 599)];
    }

    /**
     * @throws TypeSafeException When the value is negative or not an integer.
     */
    private function assertNonNegativeInteger(string $name, int $value): void
    {
        if ($value < 0) {
            throw new TypeSafeException(sprintf(
                '`%s` must be a non-negative integer, got %d.',
                $name,
                $value,
            ));
        }
    }
}
