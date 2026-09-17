<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Http;

use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\Exceptions\ApiConnectionException;
use Binnash\Typesafe\Exceptions\ApiException;
use Binnash\Typesafe\Exceptions\ApiTimeoutException;
use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Logging\HeaderRedactor;
use Binnash\Typesafe\Logging\LogLevel;
use Binnash\Typesafe\Retry\RetryPolicy;
use Binnash\Typesafe\Support\Headers;
use Binnash\Typesafe\TypeSafeClient;
use Closure;
use Http\Discovery\Exception\NotFoundException as DiscoveryNotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Sends JSON requests to the TypeSafe API with retries, timeouts, and error mapping.
 *
 * Transport is PSR-18 based: pass a client and PSR-17 factories, or let the SDK
 * discover them from installed packages.
 */
final class Transporter
{
    /** Name reported in the SDK identification headers. */
    public const SDK_NAME = 'typesafe-sdk-php';

    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    /** @var Closure(): float */
    private readonly Closure $clock;

    private int $requestCount = 0;

    /**
     * @param  ClientConfig  $config  Client settings, including headers, timeout, and retry policy.
     * @param  ClientInterface  $httpClient  PSR-18 client that performs the requests.
     * @param  RequestFactoryInterface  $requestFactory  PSR-17 request factory.
     * @param  StreamFactoryInterface  $streamFactory  PSR-17 stream factory for request bodies.
     * @param  Closure(int): void|null  $sleeper  Waits between attempts; defaults to a real sleep.
     * @param  Closure(): float|null  $clock  Reads the current time in seconds.
     */
    public function __construct(
        private readonly ClientConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ?Closure $sleeper = null,
        ?Closure $clock = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Build a transporter from configuration, discovering PSR-18 and PSR-17 services when needed.
     *
     * @throws TypeSafeException When no PSR-18 client or PSR-17 factory can be found.
     */
    public static function create(ClientConfig $config): self
    {
        try {
            $httpClient = $config->httpClient ?? Psr18ClientDiscovery::find();
            $requestFactory = $config->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
            $streamFactory = $config->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        } catch (DiscoveryNotFoundException $e) {
            throw new TypeSafeException(
                'No PSR-18 HTTP client or PSR-17 factory was found. Install a PSR-18 implementation '
                .'(such as guzzlehttp/guzzle), or pass `httpClient`, `requestFactory`, and `streamFactory` '
                .'to the TypeSafe client configuration.',
                0,
                $e,
            );
        }

        return new self($config, $httpClient, $requestFactory, $streamFactory);
    }

    /**
     * Send a JSON request, retrying eligible failures.
     *
     * @param  string  $method  HTTP method.
     * @param  string  $path  Path appended to the configured base URL.
     * @param  array<string, mixed>|null  $body  Request body, encoded as JSON when present.
     * @param  array{headers?: array<string, string>, timeout?: float}  $options  Per-call overrides.
     *
     * @throws ApiException The server returned a non-2xx response after retries.
     * @throws ApiConnectionException The request could not connect or timed out after retries.
     * @throws TypeSafeException The request could not be encoded.
     */
    public function request(string $method, string $path, ?array $body = null, array $options = []): ApiResponse
    {
        $retryPolicy = $this->config->retryPolicy;
        $timeoutMs = $this->resolveTimeoutMs($options);
        $headers = Headers::merge($this->config->defaultHeaders, $options['headers'] ?? []);
        $url = $this->config->baseURL.$path;
        $tag = sprintf('#%d %s %s', ++$this->requestCount, $method, $path);
        $payload = $this->encodeBody($body);

        for ($attempt = 0; ; $attempt++) {
            $retriesLeft = $retryPolicy->maxRetries - $attempt;
            // SDK headers come last so callers cannot clobber authentication or the JSON content type.
            $attemptHeaders = Headers::merge($headers, $this->sdkHeaders($payload !== null, $attempt));

            $this->log(LogLevel::Debug, $tag.' -> '.$url, [
                'headers' => HeaderRedactor::redact($attemptHeaders),
                'body' => $body,
            ]);

            $started = ($this->clock)();

            try {
                $response = $this->send($method, $url, $attemptHeaders, $payload);
            } catch (Throwable $e) {
                $error = $this->connectionError($e, $timeoutMs, ($this->clock)() - $started);
                $retryable = $error instanceof ApiTimeoutException
                    ? $retryPolicy->retryTimeoutErrors
                    : $retryPolicy->retryConnectionErrors;

                if ($retriesLeft <= 0 || ! $retryable) {
                    throw $error;
                }

                $this->backOff($tag, $attempt, $retriesLeft, $error->getMessage(), null, $retryPolicy);

                continue;
            }

            $elapsedMs = (int) round((($this->clock)() - $started) * 1000);
            $parsed = $this->parseResponse($response);
            $this->log(LogLevel::Info, sprintf(
                '%s <- %d in %dms%s',
                $tag,
                $parsed->status,
                $elapsedMs,
                $parsed->requestId === null ? '' : sprintf(' (request %s)', $parsed->requestId),
            ));

            if ($parsed->status >= 200 && $parsed->status < 300) {
                return $parsed;
            }

            $error = ApiException::fromResponse($parsed->status, $parsed->data, $parsed->headers);
            $this->log(LogLevel::Debug, $tag.' <- error body', ['body' => $parsed->data]);

            if ($retriesLeft <= 0 || ! $retryPolicy->isRetryableStatus($parsed->status)) {
                throw $error;
            }

            $this->backOff($tag, $attempt, $retriesLeft, (string) $parsed->status, $parsed->headers, $retryPolicy);
        }
    }

    /**
     * Headers owned by the SDK, applied after caller-supplied headers.
     *
     * `null` values remove a header so it cannot be set by callers.
     *
     * @return array<string, string|null>
     */
    private function sdkHeaders(bool $hasBody, int $attempt): array
    {
        $version = self::SDK_NAME.'/'.TypeSafeClient::VERSION;

        return [
            'Authorization' => 'Bearer '.$this->config->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $version,
            'X-TypeSafe-SDK' => $version,
            'X-TypeSafe-Runtime' => sprintf('php/%s (%s; %s)', PHP_VERSION, PHP_OS_FAMILY, PHP_SAPI),
            'Content-Type' => $hasBody ? 'application/json' : null,
            'X-TypeSafe-Retry-Count' => $attempt > 0 ? (string) $attempt : null,
        ];
    }

    /**
     * One HTTP round trip.
     *
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $url, array $headers, ?string $payload): ResponseInterface
    {
        $request = $this->requestFactory->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($payload !== null) {
            $request = $request->withBody($this->streamFactory->createStream($payload));
        }

        return $this->httpClient->sendRequest($request);
    }

    /**
     * Decode a response body and capture its metadata.
     */
    private function parseResponse(ResponseInterface $response): ApiResponse
    {
        $headers = $response->getHeaders();
        $raw = (string) $response->getBody();

        return new ApiResponse(
            status: $response->getStatusCode(),
            headers: $headers,
            data: $this->decodeBody($raw),
            requestId: Headers::requestId($headers),
        );
    }

    /**
     * Wrap a transport failure as a timeout when the attempt used up the timeout budget.
     */
    private function connectionError(Throwable $e, int $timeoutMs, float $elapsedSeconds): ApiConnectionException
    {
        if ($e instanceof ApiConnectionException) {
            return $e;
        }

        if ($elapsedSeconds * 1000 >= $timeoutMs) {
            return new ApiTimeoutException($timeoutMs, previous: $e);
        }

        return new ApiConnectionException(
            $e->getMessage() === '' ? 'Connection error.' : 'Connection error: '.$e->getMessage(),
            $e,
        );
    }

    /**
     * Wait before retrying.
     *
     * @param  array<string, list<string>>|null  $headers
     */
    private function backOff(
        string $tag,
        int $attempt,
        int $retriesLeft,
        string $reason,
        ?array $headers,
        RetryPolicy $retryPolicy,
    ): void {
        $delay = $retryPolicy->calculateDelayMs($attempt, $headers);

        $this->log(LogLevel::Info, sprintf(
            '%s retrying in %dms (retry %d/%d) after %s',
            $tag,
            $delay,
            $attempt + 1,
            $attempt + $retriesLeft,
            $reason,
        ));

        ($this->sleeper)($delay);
    }

    /**
     * Resolve the per-attempt timeout in milliseconds.
     *
     * @param  array{timeout?: float}  $options
     */
    private function resolveTimeoutMs(array $options): int
    {
        if (! isset($options['timeout'])) {
            return $this->config->timeoutMs();
        }

        $timeout = (float) $options['timeout'];

        if (! is_finite($timeout) || $timeout <= 0) {
            throw new TypeSafeException(sprintf(
                '`timeout` must be a positive number of seconds, got %s.',
                (string) $options['timeout'],
            ));
        }

        return (int) round($timeout * 1000);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function encodeBody(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        try {
            return json_encode($body, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new TypeSafeException('Request body could not be encoded as JSON: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Decode a response body, falling back to the raw text when it is not JSON.
     */
    private function decodeBody(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(LogLevel $level, string $message, array $context = []): void
    {
        $logger = $this->config->logger;

        if ($logger === null || ! $this->config->logLevel->allows($level)) {
            return;
        }

        $logger->log($level->value, '[typesafe-sdk] '.$message, $context);
    }
}
