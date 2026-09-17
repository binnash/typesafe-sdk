<?php

declare(strict_types=1);

use Binnash\Typesafe\Exceptions\ApiConnectionException;
use Binnash\Typesafe\Exceptions\ApiException;
use Binnash\Typesafe\Exceptions\ApiTimeoutException;
use Binnash\Typesafe\Exceptions\AuthenticationException;
use Binnash\Typesafe\Exceptions\BadRequestException;
use Binnash\Typesafe\Exceptions\InternalServerException;
use Binnash\Typesafe\Exceptions\NotFoundException;
use Binnash\Typesafe\Exceptions\PermissionDeniedException;
use Binnash\Typesafe\Exceptions\RateLimitException;
use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Exceptions\UnprocessableEntityException;

describe('ApiException::fromResponse', function () {
    it('maps a status code to its exception class', function (int $status, string $class) {
        $error = ApiException::fromResponse($status);

        expect($error)->toBeInstanceOf($class)
            ->and($error)->toBeInstanceOf(ApiException::class)
            ->and($error)->toBeInstanceOf(TypeSafeException::class)
            ->and($error)->toBeInstanceOf(RuntimeException::class)
            ->and($error->status)->toBe($status);
    })->with([
        [400, BadRequestException::class],
        [401, AuthenticationException::class],
        [403, PermissionDeniedException::class],
        [404, NotFoundException::class],
        [422, UnprocessableEntityException::class],
        [429, RateLimitException::class],
        [500, InternalServerException::class],
        [503, InternalServerException::class],
        [529, InternalServerException::class],
        [418, ApiException::class],
    ]);
});

describe('messages and bodies', function () {
    it('uses error.message and exposes the request id', function () {
        $error = ApiException::fromResponse(
            401,
            ['error' => ['message' => 'invalid api key']],
            ['X-TypeSafe-Request-Id' => 'req_123'],
        );

        expect($error)->toBeInstanceOf(AuthenticationException::class)
            ->and($error->getMessage())->toBe('401 invalid api key')
            ->and($error->requestId)->toBe('req_123')
            ->and($error->body)->toBe(['error' => ['message' => 'invalid api key']])
            ->and($error->getCode())->toBe(401);
    });

    it('extracts a message from a documented body shape', function (mixed $body, string $expected) {
        $error = ApiException::fromResponse(400, $body);

        expect($error->getMessage())->toBe('400 '.$expected);
    })->with([
        'plain string error' => [['error' => 'plain string'], 'plain string'],
        'top-level message' => [['message' => 'top-level message'], 'top-level message'],
        'fastapi detail' => [['detail' => 'fastapi style'], 'fastapi style'],
        'detail object' => [
            ['detail' => ['error_type' => 'api_usage_error', 'message' => 'Unknown model: x']],
            'Unknown model: x',
        ],
        'plain text body' => ['bad gateway', 'bad gateway'],
        'validation list' => [
            [
                'detail' => [
                    [
                        'type' => 'list_type',
                        'loc' => ['body', 'questions', 'q', 'score', 'criteria'],
                        'msg' => 'Input should be a valid list',
                    ],
                    [
                        'type' => 'too_short',
                        'loc' => ['body', 'questions'],
                        'msg' => 'Dictionary should have at least 1 item',
                    ],
                ],
            ],
            'questions.q.score.criteria: Input should be a valid list; questions: Dictionary should have at least 1 item',
        ],
        'validation entry without location' => [
            ['detail' => [['msg' => 'Something is wrong']]],
            'Something is wrong',
        ],
    ]);

    it('falls back to the raw body, truncated to 200 characters', function () {
        expect(ApiException::fromResponse(400, ['code' => 7])->getMessage())->toBe('400 {"code":7}');

        $long = ApiException::fromResponse(400, ['blob' => str_repeat('x', 500)])->getMessage();

        expect(mb_strlen($long))->toBe(4 + 200 + 1)
            ->and($long)->toEndWith('…');
    });

    it('ignores validation entries without a message', function () {
        expect(ApiException::fromResponse(400, ['detail' => [['loc' => ['body']]]])->getMessage())
            ->toBe('400 {"detail":[{"loc":["body"]}]}');
    });

    it('handles empty bodies and missing request ids', function () {
        $error = ApiException::fromResponse(429, null, []);

        expect($error->getMessage())->toBe('429 status code (no body)')
            ->and($error->requestId)->toBeNull()
            ->and($error->body)->toBeNull();
    });

    it('accepts a message override', function () {
        expect(ApiException::fromResponse(500, ['detail' => 'boom'], [], 'custom')->getMessage())
            ->toBe('custom');
    });
});

describe('rate limits', function () {
    it('reads the server retry delay', function () {
        expect((new RateLimitException(429, null, ['retry-after-ms' => '2500']))->retryAfterMs)->toBe(2500)
            ->and((new RateLimitException(429, null, ['Retry-After' => '3']))->retryAfterMs)->toBe(3000)
            ->and((new RateLimitException(429, null, []))->retryAfterMs)->toBeNull();
    });
});

describe('connection errors', function () {
    it('defaults to a generic message and keeps the cause', function () {
        $cause = new RuntimeException('fetch failed');
        $error = new ApiConnectionException(previous: $cause);

        expect($error->getMessage())->toBe('Connection error.')
            ->and($error->getPrevious())->toBe($cause)
            ->and($error)->toBeInstanceOf(TypeSafeException::class);
    });

    it('accepts a custom message', function () {
        expect((new ApiConnectionException('Connection error: fetch failed'))->getMessage())
            ->toBe('Connection error: fetch failed');
    });

    it('reports the timeout budget', function () {
        $error = new ApiTimeoutException(10000, previous: new RuntimeException('timed out'));

        expect($error->getMessage())->toBe('Request timed out after 10000ms.')
            ->and($error->timeoutMs)->toBe(10000)
            ->and($error)->toBeInstanceOf(ApiConnectionException::class);
    });
});
