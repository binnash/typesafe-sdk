<?php

declare(strict_types=1);

use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Retry\RetryPolicy;

it('applies every documented default', function () {
    $policy = RetryPolicy::default();

    expect($policy->maxRetries)->toBe(2)
        ->and($policy->backoffInitialMs)->toBe(500)
        ->and($policy->backoffMaxMs)->toBe(5000)
        ->and($policy->backoffJitter)->toBe(0.25)
        ->and($policy->respectRetryAfter)->toBeTrue()
        ->and($policy->maxRetryAfterMs)->toBe(60000)
        ->and($policy->retryConnectionErrors)->toBeTrue()
        ->and($policy->retryTimeoutErrors)->toBeTrue();
});

it('retries 408, 429, and 5xx responses by default', function (int $status, bool $retryable) {
    expect((new RetryPolicy)->isRetryableStatus($status))->toBe($retryable);
})->with([
    [408, true],
    [429, true],
    [500, true],
    [503, true],
    [599, true],
    [400, false],
    [401, false],
    [422, false],
    [418, false],
]);

it('accepts a custom status list', function () {
    $policy = new RetryPolicy(httpStatuses: [502, 504]);

    expect($policy->isRetryableStatus(502))->toBeTrue()
        ->and($policy->isRetryableStatus(503))->toBeFalse()
        ->and($policy->statuses())->toBe([502, 504]);
});

it('rejects invalid retry settings', function (array $arguments, string $expected) {
    expect(fn () => new RetryPolicy(...$arguments))->toThrow(TypeSafeException::class, $expected);
})->with([
    'negative retries' => [['maxRetries' => -1], '`maxRetries` must be a non-negative integer'],
    'negative initial backoff' => [['backoffInitialMs' => -1], '`backoffInitialMs` must be a non-negative integer'],
    'negative max backoff' => [['backoffMaxMs' => -1], '`backoffMaxMs` must be a non-negative integer'],
    'negative max retry-after' => [['maxRetryAfterMs' => -1], '`maxRetryAfterMs` must be a non-negative integer'],
    'jitter above one' => [['backoffJitter' => 1.5], '`backoffJitter` must be between 0 and 1'],
    'jitter below zero' => [['backoffJitter' => -0.5], '`backoffJitter` must be between 0 and 1'],
    'status out of range' => [['httpStatuses' => [99]], '`httpStatuses` must contain HTTP status codes'],
    'status above range' => [['httpStatuses' => [1000]], '`httpStatuses` must contain HTTP status codes'],
]);

it('parses retry-after-ms ahead of retry-after', function () {
    expect(RetryPolicy::parseRetryAfter(['retry-after-ms' => '1500', 'Retry-After' => '30']))
        ->toBe(1500);
});

it('parses a numeric retry-after value as seconds', function () {
    expect(RetryPolicy::parseRetryAfter(['Retry-After' => '2.5']))->toBe(2500)
        ->and(RetryPolicy::parseRetryAfter(['retry-after' => '0']))->toBe(0);
});

it('parses an HTTP-date retry-after value relative to now', function () {
    $now = 1_700_000_000_000;

    expect(RetryPolicy::parseRetryAfter(
        ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', $now / 1000 + 30)],
        $now,
    ))->toBe(30_000);
});

it('returns null for absent or invalid retry-after values', function (array $headers) {
    expect(RetryPolicy::parseRetryAfter($headers))->toBeNull();
})->with([
    'none' => [[]],
    'invalid ms' => [['retry-after-ms' => 'soon']],
    'negative ms' => [['retry-after-ms' => '-5']],
    'negative seconds' => [['Retry-After' => '-5']],
    'garbage date' => [['Retry-After' => 'not-a-date']],
]);

it('uses an allowed server delay before exponential backoff', function () {
    $policy = new RetryPolicy;

    expect($policy->calculateDelayMs(0, ['retry-after-ms' => '1200'], 0.0))->toBe(1200);
});

it('ignores server delays longer than maxRetryAfterMs', function () {
    $policy = new RetryPolicy(maxRetryAfterMs: 1000);

    expect($policy->calculateDelayMs(0, ['retry-after-ms' => '5000'], 0.0))->toBe(500);
});

it('grows the backoff exponentially up to backoffMaxMs', function (int $attempt, int $expected) {
    $policy = new RetryPolicy;

    expect($policy->calculateDelayMs($attempt, null, 0.0))->toBe($expected);
})->with([
    [0, 500],
    [1, 1000],
    [2, 2000],
    [3, 4000],
    [4, 5000],
    [5, 5000],
]);

it('subtracts jitter from the backoff delay', function () {
    $policy = new RetryPolicy;

    expect($policy->calculateDelayMs(0, null, 1.0))->toBe(375)
        ->and($policy->calculateDelayMs(1, null, 0.5))->toBe(875);
});

it('ignores retry-after when respectRetryAfter is disabled', function () {
    $policy = new RetryPolicy(respectRetryAfter: false);

    expect($policy->calculateDelayMs(0, ['retry-after-ms' => '1200'], 0.0))->toBe(500);
});

describe('overrides', function () {
    it('replaces only the supplied settings', function () {
        $base = new RetryPolicy;
        $updated = $base->with(maxRetries: 0);

        expect($updated->maxRetries)->toBe(0)
            ->and($updated->backoffInitialMs)->toBe($base->backoffInitialMs)
            ->and($updated->backoffMaxMs)->toBe($base->backoffMaxMs)
            ->and($updated->backoffJitter)->toBe($base->backoffJitter)
            ->and($updated->statuses())->toBe($base->statuses())
            ->and($updated->respectRetryAfter)->toBeTrue()
            ->and($updated->maxRetryAfterMs)->toBe(60000)
            ->and($updated->retryConnectionErrors)->toBeTrue()
            ->and($updated->retryTimeoutErrors)->toBeTrue();
    });

    it('leaves the original policy untouched', function () {
        $base = new RetryPolicy;
        $derived = $base->with(maxRetries: 9, httpStatuses: [502]);

        expect($base->maxRetries)->toBe(2)
            ->and($base->isRetryableStatus(503))->toBeTrue()
            ->and($derived->maxRetries)->toBe(9)
            ->and($derived->statuses())->toBe([502]);
    });

    it('allows clearing the retryable status list', function () {
        $updated = (new RetryPolicy)->with(httpStatuses: []);

        expect($updated->statuses())->toBe([])
            ->and($updated->isRetryableStatus(503))->toBeFalse();
    });

    it('overrides boolean settings', function () {
        $updated = (new RetryPolicy)->with(
            respectRetryAfter: false,
            retryConnectionErrors: false,
            retryTimeoutErrors: false,
        );

        expect($updated->respectRetryAfter)->toBeFalse()
            ->and($updated->retryConnectionErrors)->toBeFalse()
            ->and($updated->retryTimeoutErrors)->toBeFalse();
    });

    it('validates replacement settings', function () {
        expect(fn () => (new RetryPolicy)->with(maxRetries: -1))
            ->toThrow(TypeSafeException::class, '`maxRetries` must be a non-negative integer');
    });
});

describe('RetryPolicy::from', function () {
    it('returns the base policy when there are no overrides', function () {
        $base = new RetryPolicy(maxRetries: 5);

        expect(RetryPolicy::from(null, $base))->toBe($base);
    });

    it('uses the SDK policy as the default base', function () {
        expect(RetryPolicy::from(['maxRetries' => 0])->backoffInitialMs)->toBe(500);
    });

    it('returns a complete policy unchanged', function () {
        $policy = new RetryPolicy(maxRetries: 7);

        expect(RetryPolicy::from($policy))->toBe($policy);
    });

    it('overlays a partial map onto the base', function () {
        $base = new RetryPolicy(maxRetries: 4, backoffInitialMs: 250, backoffJitter: 0.0);
        $resolved = RetryPolicy::from(['maxRetries' => 0, 'backoffMaxMs' => 100], $base);

        expect($resolved->maxRetries)->toBe(0)
            ->and($resolved->backoffMaxMs)->toBe(100)
            ->and($resolved->backoffInitialMs)->toBe(250)
            ->and($resolved->backoffJitter)->toBe(0.0);
    });

    it('rejects an unknown override key', function () {
        expect(fn () => RetryPolicy::from(['nope' => 1]))
            ->toThrow(TypeSafeException::class, 'Invalid retry options: Unknown named parameter $nope');
    });

    it('rethrows its own validation errors', function () {
        expect(fn () => RetryPolicy::from(['backoffJitter' => 2.0]))
            ->toThrow(TypeSafeException::class, '`backoffJitter` must be between 0 and 1');
    });
});
