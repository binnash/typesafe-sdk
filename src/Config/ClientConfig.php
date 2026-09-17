<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Config;

use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Logging\LogLevel;
use Binnash\Typesafe\Retry\RetryPolicy;
use JsonSerializable;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Immutable client settings.
 *
 * The SDK never reads the process environment; resolve values from your
 * framework's configuration and pass them here explicitly.
 */
final class ClientConfig implements JsonSerializable
{
    /** API root used when none is configured. */
    public const DEFAULT_BASE_URL = 'https://api.typesafe.ai';

    /** Model used when a request omits one. */
    public const DEFAULT_MODEL = 'jev-latest';

    /** Timeout per attempt, in seconds, when none is configured. */
    public const DEFAULT_TIMEOUT = 10.0;

    /** API key sent as a bearer token. */
    public readonly string $apiKey;

    /** API root without trailing slashes. */
    public readonly string $baseURL;

    /** Model used when a request omits one. */
    public readonly string $defaultModel;

    /** Timeout per attempt, in seconds. There is no total retry budget. */
    public readonly float $timeout;

    /** Retry settings applied to each request. */
    public readonly RetryPolicy $retryPolicy;

    /** Optional PSR-3 logger; the SDK logs nothing when absent. */
    public readonly ?LoggerInterface $logger;

    /** Log verbosity applied to the logger. */
    public readonly LogLevel $logLevel;

    /** Additional headers sent with each request, overridden by SDK-owned headers. */
    public readonly array $defaultHeaders;

    /** PSR-18 client; discovered from installed packages when absent. */
    public readonly ?ClientInterface $httpClient;

    /** PSR-17 request factory; discovered from installed packages when absent. */
    public readonly ?RequestFactoryInterface $requestFactory;

    /** PSR-17 stream factory; discovered from installed packages when absent. */
    public readonly ?StreamFactoryInterface $streamFactory;

    /**
     * @param  string  $apiKey  API key sent as a bearer token.
     * @param  string  $baseURL  API root; trailing slashes are stripped.
     * @param  string  $defaultModel  Model used when a request omits one.
     * @param  float  $timeout  Timeout per attempt, in seconds.
     * @param  RetryPolicy|null  $retryPolicy  Retry settings; defaults to the standard policy.
     * @param  LoggerInterface|null  $logger  Optional PSR-3 logger.
     * @param  string|LogLevel  $logLevel  Log verbosity, as a level or its string value.
     * @param  array<string, string>  $defaultHeaders  Additional headers for each request.
     * @param  ClientInterface|null  $httpClient  PSR-18 client override.
     * @param  RequestFactoryInterface|null  $requestFactory  PSR-17 request factory override.
     * @param  StreamFactoryInterface|null  $streamFactory  PSR-17 stream factory override.
     *
     * @throws TypeSafeException When the API key is missing or any setting is invalid.
     */
    public function __construct(
        string $apiKey,
        string $baseURL = self::DEFAULT_BASE_URL,
        string $defaultModel = self::DEFAULT_MODEL,
        float $timeout = self::DEFAULT_TIMEOUT,
        ?RetryPolicy $retryPolicy = null,
        ?LoggerInterface $logger = null,
        string|LogLevel $logLevel = LogLevel::Warn,
        array $defaultHeaders = [],
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->apiKey = trim($apiKey);

        if ($this->apiKey === '') {
            throw new TypeSafeException(
                'No API key was provided. Pass `apiKey` to the TypeSafe client configuration.'
            );
        }

        $this->baseURL = rtrim(trim($baseURL), '/');

        if ($this->baseURL === '') {
            throw new TypeSafeException('`baseURL` must not be empty.');
        }

        $this->defaultModel = trim($defaultModel);

        if ($this->defaultModel === '') {
            throw new TypeSafeException('`defaultModel` must not be empty.');
        }

        if (! is_finite($timeout) || $timeout <= 0) {
            throw new TypeSafeException(sprintf(
                '`timeout` must be a positive number of seconds, got %s.',
                (string) $timeout,
            ));
        }

        $this->timeout = $timeout;
        $this->retryPolicy = $retryPolicy ?? RetryPolicy::default();
        $this->logger = $logger;
        $this->logLevel = $logLevel instanceof LogLevel ? $logLevel : LogLevel::fromString($logLevel);
        $this->defaultHeaders = $defaultHeaders;
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    /**
     * Build a configuration from an API key or a map of constructor arguments.
     *
     * @param  string|array<string, mixed>  $config  API key, or named constructor arguments.
     *
     * @throws TypeSafeException When the config is malformed.
     */
    public static function make(string|array $config): self
    {
        if (is_string($config)) {
            return new self($config);
        }

        try {
            return new self(...$config);
        } catch (TypeSafeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TypeSafeException('Invalid TypeSafe client configuration: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Timeout per attempt in milliseconds.
     */
    public function timeoutMs(): int
    {
        return (int) round($this->timeout * 1000);
    }

    /**
     * Keep the API key out of dumps, logs, and JSON encodings.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '***',
            'baseURL' => $this->baseURL,
            'defaultModel' => $this->defaultModel,
            'timeout' => $this->timeout,
            'retryPolicy' => $this->retryPolicy,
            'logger' => $this->logger,
            'logLevel' => $this->logLevel,
            'defaultHeaders' => $this->defaultHeaders,
            'httpClient' => $this->httpClient,
            'requestFactory' => $this->requestFactory,
            'streamFactory' => $this->streamFactory,
        ];
    }

    /**
     * Serialize with the API key redacted.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
