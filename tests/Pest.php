<?php

declare(strict_types=1);

use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\Http\Transporter;
use Binnash\Typesafe\Tests\TestCase;
use Binnash\Typesafe\TypeSafeClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Integration');

/**
 * Capture `var_dump()` output for an object, which honors `__debugInfo()`.
 */
function dumpOf(object $value): string
{
    ob_start();
    var_dump($value);

    return (string) ob_get_clean();
}

/**
 * Build an HTTP response with a JSON body.
 *
 * @param  array<string, mixed>|list<mixed>|null  $data
 * @param  array<string, string|list<string>>  $headers
 */
function jsonResponse(int $status = 200, mixed $data = [], array $headers = []): ResponseInterface
{
    return new Response(
        $status,
        $headers + ['Content-Type' => 'application/json'],
        $data === null ? null : json_encode($data),
    );
}

/**
 * Build an HTTP response with a raw body.
 *
 * @param  array<string, string|list<string>>  $headers
 */
function textResponse(int $status, string $body, array $headers = []): ResponseInterface
{
    return new Response($status, $headers, $body);
}

/**
 * Build a client wired to a fake HTTP client and, optionally, a fake sleeper and clock.
 *
 * @param  array<string, mixed>  $config  Named `ClientConfig` overrides.
 */
function makeClient(
    ClientInterface $http,
    array $config = [],
    ?Closure $sleeper = null,
    ?Closure $clock = null,
): TypeSafeClient {
    $factory = new HttpFactory;

    $clientConfig = ClientConfig::make(array_merge([
        'apiKey' => 'test-key',
        'baseURL' => 'https://api.test',
        'httpClient' => $http,
        'requestFactory' => $factory,
        'streamFactory' => $factory,
    ], $config));

    return new TypeSafeClient(
        $clientConfig,
        new Transporter($clientConfig, $http, $factory, $factory, $sleeper, $clock),
    );
}

/**
 * Record the delays a retry policy passes to the sleeper.
 *
 * @param  list<int>  $delays
 */
function recordSleeps(array &$delays): Closure
{
    return function (int $milliseconds) use (&$delays): void {
        $delays[] = $milliseconds;
    };
}

/**
 * A clock that reports each supplied timestamp in order, repeating the last one.
 *
 * @param  list<float>  $times
 */
function clockFrom(array $times): Closure
{
    $index = 0;

    return function () use (&$index, $times): float {
        $time = $times[min($index, count($times) - 1)];
        $index++;

        return $time;
    };
}

/**
 * The TypeSafe API key for live integration tests, or `null` when it is unset.
 *
 * The SDK itself never reads the environment; only this test suite does, to decide
 * whether live calls are possible.
 */
function liveApiKey(): ?string
{
    $key = getenv('TYPESAFE_API_KEY');

    if ($key === false || trim($key) === '') {
        $key = $_ENV['TYPESAFE_API_KEY'] ?? '';
    }

    return is_string($key) && trim($key) !== '' ? trim($key) : null;
}

/**
 * A client pointed at the live API, for integration tests only.
 *
 * @param  array<string, mixed>  $config  Named `ClientConfig` overrides.
 */
function liveClient(?string $apiKey = null, array $config = []): TypeSafeClient
{
    $baseURL = getenv('TYPESAFE_BASE_URL');

    return new TypeSafeClient(ClientConfig::make(array_merge([
        'apiKey' => $apiKey ?? liveApiKey() ?? '',
        'baseURL' => $baseURL === false || $baseURL === '' ? ClientConfig::DEFAULT_BASE_URL : $baseURL,
        'timeout' => 120.0,
    ], $config)));
}

/**
 * Print a labelled value to STDERR so live responses are visible without being asserted on.
 */
function showLive(string $label, mixed $value): void
{
    fwrite(STDERR, sprintf(
        "\n--- %s ---\n%s\n",
        $label,
        json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ));
}

/**
 * Reason shown when the live integration suite is skipped.
 */
function liveSkipReason(): string
{
    return 'Set TYPESAFE_API_KEY to run the live integration tests.';
}
