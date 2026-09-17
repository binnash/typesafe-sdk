<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Laravel;

use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Retry\RetryPolicy;
use Binnash\Typesafe\TypeSafeClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers the TypeSafe client in a Laravel application.
 *
 * Settings come from `config/typesafe.php`, which resolves the `TYPESAFE_*`
 * environment variables. The client and its configuration are shared singletons.
 */
final class TypeSafeServiceProvider extends ServiceProvider
{
    /** Tag used to publish the configuration file. */
    public const CONFIG_TAG = 'typesafe-config';

    /** Config key the bridge reads from. */
    public const CONFIG_KEY = 'typesafe';

    /** Container alias for the shared client. */
    public const ALIAS = 'typesafe';

    public function register(): void
    {
        $this->mergeConfigFrom(self::defaultConfigPath(), self::CONFIG_KEY);

        $this->app->singleton(ClientConfig::class, fn (Container $app): ClientConfig => $this->makeConfig($app));

        $this->app->singleton(TypeSafeClient::class, fn (Container $app): TypeSafeClient => new TypeSafeClient(
            $app->make(ClientConfig::class),
        ));

        $this->app->alias(TypeSafeClient::class, self::ALIAS);
    }

    public function boot(): void
    {
        $this->publishes(
            [self::defaultConfigPath() => $this->app->configPath('typesafe.php')],
            self::CONFIG_TAG,
        );
    }

    /**
     * Services this provider offers, as reported to Laravel's tooling.
     *
     * @return list<string>
     */
    public function provides(): array
    {
        return [ClientConfig::class, TypeSafeClient::class, self::ALIAS];
    }

    /**
     * Path to the package's configuration file.
     */
    public static function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/typesafe.php';
    }

    /**
     * Build client settings from the application's configuration.
     *
     * Explicit configuration wins; otherwise the container is consulted for a
     * bound PSR-18 client, PSR-17 factories, and logger.
     *
     * @throws TypeSafeException When the API key is missing or a setting is invalid.
     */
    private function makeConfig(Container $app): ClientConfig
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $apiKey = $config->get(self::CONFIG_KEY.'.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new TypeSafeException(
                'No TypeSafe API key was provided. Set TYPESAFE_API_KEY in your .env file, '
                .'or pass `api_key` to config/typesafe.php.'
            );
        }

        return new ClientConfig(
            apiKey: $apiKey,
            baseURL: (string) $config->get(self::CONFIG_KEY.'.base_url', ClientConfig::DEFAULT_BASE_URL),
            defaultModel: (string) $config->get(self::CONFIG_KEY.'.default_model', ClientConfig::DEFAULT_MODEL),
            timeout: (float) $config->get(self::CONFIG_KEY.'.timeout', ClientConfig::DEFAULT_TIMEOUT),
            retryPolicy: $this->makeRetryPolicy($config),
            logger: $this->resolve($app, LoggerInterface::class, $config->get(self::CONFIG_KEY.'.logger')),
            logLevel: (string) $config->get(self::CONFIG_KEY.'.log_level', 'warn'),
            httpClient: $this->resolve($app, ClientInterface::class, $config->get(self::CONFIG_KEY.'.http_client')),
            requestFactory: $this->resolve($app, RequestFactoryInterface::class, $config->get(self::CONFIG_KEY.'.request_factory')),
            streamFactory: $this->resolve($app, StreamFactoryInterface::class, $config->get(self::CONFIG_KEY.'.stream_factory')),
        );
    }

    /**
     * Build the retry policy from the `typesafe.retry` configuration block.
     */
    private function makeRetryPolicy(ConfigRepository $config): RetryPolicy
    {
        /** @var array<string, mixed> $retry */
        $retry = (array) $config->get(self::CONFIG_KEY.'.retry', []);

        return new RetryPolicy(
            maxRetries: (int) ($retry['max_retries'] ?? RetryPolicy::DEFAULT_MAX_RETRIES),
            backoffInitialMs: (int) ($retry['backoff_initial_ms'] ?? RetryPolicy::DEFAULT_BACKOFF_INITIAL_MS),
            backoffMaxMs: (int) ($retry['backoff_max_ms'] ?? RetryPolicy::DEFAULT_BACKOFF_MAX_MS),
            backoffJitter: (float) ($retry['backoff_jitter'] ?? RetryPolicy::DEFAULT_BACKOFF_JITTER),
            respectRetryAfter: (bool) ($retry['respect_retry_after'] ?? true),
        );
    }

    /**
     * Prefer an explicit config value, then a container binding, then `null` so the SDK can discover one.
     */
    private function resolve(Container $app, string $abstract, mixed $configured): ?object
    {
        if ($configured instanceof $abstract) {
            return $configured;
        }

        return $app->bound($abstract) ? $app->make($abstract) : null;
    }
}
