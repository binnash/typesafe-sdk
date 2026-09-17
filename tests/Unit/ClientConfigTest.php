<?php

declare(strict_types=1);

use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Logging\LogLevel;
use Binnash\Typesafe\Retry\RetryPolicy;
use Psr\Log\NullLogger;

it('applies documented defaults', function () {
    $config = new ClientConfig('test-key');

    expect($config->apiKey)->toBe('test-key')
        ->and($config->baseURL)->toBe('https://api.typesafe.ai')
        ->and($config->defaultModel)->toBe('jev-latest')
        ->and($config->timeout)->toBe(10.0)
        ->and($config->timeoutMs())->toBe(10000)
        ->and($config->logLevel)->toBe(LogLevel::Warn)
        ->and($config->logger)->toBeNull()
        ->and($config->defaultHeaders)->toBe([])
        ->and($config->httpClient)->toBeNull()
        ->and($config->requestFactory)->toBeNull()
        ->and($config->streamFactory)->toBeNull()
        ->and($config->retryPolicy)->toEqual(RetryPolicy::default());
});

it('strips trailing slashes from the base URL', function () {
    expect((new ClientConfig('k', baseURL: 'https://x.test///'))->baseURL)->toBe('https://x.test');
});

it('trims the API key and rejects a blank one', function () {
    expect((new ClientConfig('  secret  '))->apiKey)->toBe('secret');

    expect(fn () => new ClientConfig('   '))->toThrow(
        TypeSafeException::class,
        'No API key was provided. Pass `apiKey` to the TypeSafe client configuration.',
    );

    expect(fn () => new ClientConfig(''))->toThrow(TypeSafeException::class);
});

it('rejects blank base URLs and model names', function () {
    expect(fn () => new ClientConfig('k', baseURL: '/'))->toThrow(TypeSafeException::class, '`baseURL` must not be empty.');
    expect(fn () => new ClientConfig('k', defaultModel: ' '))->toThrow(TypeSafeException::class, '`defaultModel` must not be empty.');
});

it('rejects a non-positive timeout', function (float $timeout) {
    expect(fn () => new ClientConfig('k', timeout: $timeout))->toThrow(TypeSafeException::class, '`timeout` must be a positive number of seconds');
})->with([0.0, -1.0, INF]);

it('accepts every log level as a string or enum', function (string $level) {
    expect((new ClientConfig('k', logLevel: $level))->logLevel->value)->toBe($level)
        ->and((new ClientConfig('k', logLevel: LogLevel::from($level)))->logLevel->value)->toBe($level);
})->with(LogLevel::values());

it('rejects an invalid log level', function () {
    expect(fn () => new ClientConfig('k', logLevel: 'loud'))->toThrow(
        TypeSafeException::class,
        'Invalid log level "loud". Expected one of: debug, info, warn, error, off.',
    );
});

it('keeps custom settings', function () {
    $logger = new NullLogger;
    $policy = new RetryPolicy(maxRetries: 0);

    $config = new ClientConfig(
        apiKey: 'k',
        baseURL: 'https://example.test',
        defaultModel: 'jev-1.13.0',
        timeout: 2.5,
        retryPolicy: $policy,
        logger: $logger,
        logLevel: LogLevel::Debug,
        defaultHeaders: ['X-Trace' => 'abc'],
    );

    expect($config->baseURL)->toBe('https://example.test')
        ->and($config->defaultModel)->toBe('jev-1.13.0')
        ->and($config->timeoutMs())->toBe(2500)
        ->and($config->retryPolicy)->toBe($policy)
        ->and($config->logger)->toBe($logger)
        ->and($config->logLevel)->toBe(LogLevel::Debug)
        ->and($config->defaultHeaders)->toBe(['X-Trace' => 'abc']);
});

describe('ClientConfig::make', function () {
    it('accepts an API key string', function () {
        expect(ClientConfig::make('secret')->apiKey)->toBe('secret');
    });

    it('accepts a map of named arguments', function () {
        $config = ClientConfig::make([
            'apiKey' => 'secret',
            'baseURL' => 'https://x.test/',
            'timeout' => 1.5,
            'logLevel' => 'info',
        ]);

        expect($config->apiKey)->toBe('secret')
            ->and($config->baseURL)->toBe('https://x.test')
            ->and($config->timeout)->toBe(1.5)
            ->and($config->logLevel)->toBe(LogLevel::Info);
    });

    it('rejects unknown options with a clear message', function () {
        expect(fn () => ClientConfig::make(['apiKey' => 'k', 'nope' => true]))->toThrow(
            TypeSafeException::class,
            'Invalid TypeSafe client configuration: Unknown named parameter $nope',
        );
    });

    it('rethrows its own validation errors', function () {
        expect(fn () => ClientConfig::make(['apiKey' => '']))->toThrow(TypeSafeException::class, 'No API key was provided');
    });
});

it('never exposes the API key through debug info', function () {
    $config = new ClientConfig('super-secret');

    expect($config->__debugInfo()['apiKey'])->toBe('***')
        ->and(dumpOf($config))->not->toContain('super-secret')
        ->and(json_encode($config))->not->toContain('super-secret')
        ->and(json_decode(json_encode($config), true)['apiKey'])->toBe('***');
});
