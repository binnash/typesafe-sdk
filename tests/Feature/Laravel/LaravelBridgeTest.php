<?php

declare(strict_types=1);

use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\DTO\ModelCard;
use Binnash\Typesafe\DTO\SystemOneResult;
use Binnash\Typesafe\Exceptions\ApiException;
use Binnash\Typesafe\Exceptions\AuthenticationException;
use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Laravel\Facades\TypeSafe;
use Binnash\Typesafe\Laravel\TypeSafeServiceProvider;
use Binnash\Typesafe\Retry\RetryPolicy;
use Binnash\Typesafe\Tests\Support\FakeHttpClient;
use Binnash\Typesafe\TypeSafeClient;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

const LIVE_ANSWERS = [
    'model' => 'jev-1.13.0',
    'answers' => ['is_billing' => ['type' => 'noul', 'noul' => 0.94]],
    'usage' => ['input_tokens' => 312, 'output_tokens' => 48],
];

const LIVE_MODELS = [
    'models' => [
        ['name' => 'jev-latest', 'description' => 'Alias', 'release_date' => '2026-01-01'],
    ],
];

afterEach(function () {
    foreach (['TYPESAFE_TIMEOUT', 'TYPESAFE_DEFAULT_MODEL', 'TYPESAFE_MAX_RETRIES'] as $name) {
        putenv($name);
        unset($_ENV[$name]);
    }
});

describe('package auto-discovery', function () {
    it('is discovered by Laravel from the package composer.json', function () {
        $base = sys_get_temp_dir().'/typesafe-discovery-'.bin2hex(random_bytes(6));
        $package = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        mkdir($base.'/vendor/composer', 0777, true);
        mkdir($base.'/bootstrap/cache', 0777, true);
        file_put_contents($base.'/composer.json', '{}');
        file_put_contents(
            $base.'/vendor/composer/installed.json',
            json_encode(['packages' => [$package]], JSON_THROW_ON_ERROR),
        );

        try {
            $manifest = new PackageManifest(new Filesystem, $base, $base.'/bootstrap/cache/packages.php');
            $manifest->build();

            expect($manifest->providers())->toContain(TypeSafeServiceProvider::class)
                ->and($manifest->aliases())->toBe(['TypeSafe' => TypeSafe::class])
                ->and(class_exists(TypeSafeServiceProvider::class))->toBeTrue()
                ->and(class_exists(TypeSafe::class))->toBeTrue()
                ->and(is_subclass_of(TypeSafeServiceProvider::class, ServiceProvider::class))->toBeTrue()
                ->and(is_subclass_of(TypeSafe::class, Facade::class))->toBeTrue();
        } finally {
            (new Filesystem)->deleteDirectory($base);
        }
    });
});

describe('configuration', function () {
    it('merges the package configuration with its defaults', function () {
        expect(config('typesafe.api_key'))->toBeNull()
            ->and(config('typesafe.base_url'))->toBe('https://api.typesafe.ai')
            ->and(config('typesafe.default_model'))->toBe('jev-latest')
            ->and(config('typesafe.timeout'))->toBe(10.0)
            ->and(config('typesafe.log_level'))->toBe('warn')
            ->and(config('typesafe.retry'))->toBe([
                'max_retries' => 2,
                'backoff_initial_ms' => 500,
                'backoff_max_ms' => 5000,
                'backoff_jitter' => 0.25,
                'respect_retry_after' => true,
            ]);
    });

    it('resolves the TYPESAFE_* environment variables', function () {
        $_ENV['TYPESAFE_TIMEOUT'] = '20';
        $_ENV['TYPESAFE_DEFAULT_MODEL'] = 'jev-1.13.0';
        $_ENV['TYPESAFE_MAX_RETRIES'] = '5';

        /** @var array<string, mixed> $config */
        $config = require TypeSafeServiceProvider::defaultConfigPath();

        expect($config['timeout'])->toBe(20.0)
            ->and($config['default_model'])->toBe('jev-1.13.0')
            ->and($config['retry']['max_retries'])->toBe(5)
            ->and($config['retry']['backoff_initial_ms'])->toBe(500);
    });

    it('applies application configuration to the resolved client', function () {
        $this->setConfig('base_url', 'https://proxy.test/');
        $this->setConfig('default_model', 'jev-1.13.0');
        $this->setConfig('timeout', 30.5);
        $this->setConfig('log_level', 'debug');
        $this->setConfig('retry', ['max_retries' => 7, 'backoff_initial_ms' => 25]);
        $this->setConfig('api_key', 'config-key');

        $config = TypeSafe::config();

        expect($config->apiKey)->toBe('config-key')
            ->and($config->baseURL)->toBe('https://proxy.test')
            ->and($config->defaultModel)->toBe('jev-1.13.0')
            ->and($config->timeout)->toBe(30.5)
            ->and($config->logLevel->value)->toBe('debug')
            ->and($config->retryPolicy->maxRetries)->toBe(7)
            ->and($config->retryPolicy->backoffInitialMs)->toBe(25)
            ->and($config->retryPolicy->backoffMaxMs)->toBe(5000);
    });

    it('explains how to fix a missing API key', function () {
        expect(fn () => $this->app->make(ClientConfig::class))->toThrow(
            TypeSafeException::class,
            'No TypeSafe API key was provided. Set TYPESAFE_API_KEY in your .env file',
        );
    });

    it('registers the config publish tag', function () {
        $paths = ServiceProvider::pathsToPublish(TypeSafeServiceProvider::class, TypeSafeServiceProvider::CONFIG_TAG);

        expect($paths)->toBe([
            TypeSafeServiceProvider::defaultConfigPath() => $this->app->configPath('typesafe.php'),
        ])
            ->and(file_exists(TypeSafeServiceProvider::defaultConfigPath()))->toBeTrue();
    });
});

describe('container bindings', function () {
    beforeEach(function () {
        $this->setConfig('api_key', 'test-key');
        $this->fakeTransport();
    });

    it('shares one client under the class name and the alias', function () {
        $byClass = $this->app->make(TypeSafeClient::class);
        $byAlias = $this->app->make(TypeSafeServiceProvider::ALIAS);
        $byFacade = TypeSafe::getFacadeRoot();

        expect($byClass)->toBe($byAlias)
            ->and($byFacade)->toBe($byClass)
            ->and($this->app->make(ClientConfig::class))->toBe($byClass->config);
    });

    it('resolves the client through dependency injection', function () {
        $resolved = null;

        $this->app->call(function (TypeSafeClient $client) use (&$resolved): void {
            $resolved = $client;
        });

        expect($resolved)->toBe($this->app->make(TypeSafeClient::class));
    });

    it('injects Laravel logger into the client configuration', function () {
        $logger = $this->app->make(ClientConfig::class)->logger;

        expect($logger)->toBeInstanceOf(LoggerInterface::class)
            ->and($logger)->toBe($this->app->make(LoggerInterface::class));
    });

    it('prefers an explicit http client over the container binding', function () {
        $explicit = new FakeHttpClient([jsonResponse(200, LIVE_MODELS)]);
        $this->setConfig('http_client', $explicit);

        TypeSafe::models()->list();

        expect($explicit->requests)->toHaveCount(1)
            ->and($this->http->requests)->toBe([]);
    });

    it('keeps the SDK free of environment reads', function () {
        // The bridge resolves env vars; the client only ever sees explicit values.
        $this->setConfig('api_key', 'from-config');

        expect(TypeSafe::config()->apiKey)->toBe('from-config');
    });
});

describe('facade usage', function () {
    beforeEach(function () {
        $this->setConfig('api_key', 'test-key');
    });

    afterEach(function () {
        Facade::clearResolvedInstances();
    });

    it('lists models through the facade', function () {
        $this->fakeTransport([
            jsonResponse(200, LIVE_MODELS, ['x-typesafe-request-id' => 'req_1']),
            jsonResponse(200, LIVE_MODELS, ['x-typesafe-request-id' => 'req_2']),
        ]);

        $models = TypeSafe::models()->list();
        $response = TypeSafe::models()->listWithResponse();

        expect($models)->toHaveCount(1)
            ->and($models[0])->toBeInstanceOf(ModelCard::class)
            ->and($models[0]->name)->toBe('jev-latest')
            ->and($models[0]->releaseDate)->toBe('2026-01-01')
            ->and($response->status)->toBe(200)
            ->and($response->requestId)->toBe('req_2');
    });

    it('evaluates questions through the facade', function () {
        $http = $this->fakeTransport([jsonResponse(200, LIVE_ANSWERS)]);

        $result = TypeSafe::systemOne(
            ['document' => 'I was charged twice.'],
            ['is_billing' => noul('Is this about billing?')],
        );

        $body = json_decode((string) $http->lastRequest()->getBody(), true);

        expect($result)->toBeInstanceOf(SystemOneResult::class)
            ->and($result->answers->is_billing->noul)->toBe(0.94)
            ->and($body['model'])->toBe('jev-latest')
            ->and($body['questions']['is_billing']['type'])->toBe('noul');
    });

    it('passes per-call options and extra fields through the facade', function () {
        $http = $this->fakeTransport([jsonResponse(200, LIVE_ANSWERS)]);

        TypeSafe::systemOne(
            'state',
            ['is_billing' => noul('?')],
            model: 'jev-1.13.0',
            options: ['retry' => ['maxRetries' => 0]],
            extra: ['future_option' => true],
        );

        $body = json_decode((string) $http->lastRequest()->getBody(), true);

        expect($body['model'])->toBe('jev-1.13.0')
            ->and($body['future_option'])->toBeTrue();
    });

    it('binds a dedicated retry policy from configuration', function () {
        $this->setConfig('retry', [
            'max_retries' => 0,
            'backoff_initial_ms' => 10,
            'backoff_max_ms' => 20,
            'backoff_jitter' => 0.5,
            'respect_retry_after' => false,
        ]);

        $policy = TypeSafe::config()->retryPolicy;

        expect($policy)->toBeInstanceOf(RetryPolicy::class)
            ->and($policy->maxRetries)->toBe(0)
            ->and($policy->backoffInitialMs)->toBe(10)
            ->and($policy->backoffMaxMs)->toBe(20)
            ->and($policy->backoffJitter)->toBe(0.5)
            ->and($policy->respectRetryAfter)->toBeFalse();
    });

    it('surfaces api errors to the application', function () {
        $this->fakeTransport([
            jsonResponse(401, ['error' => ['message' => 'invalid api key']], ['x-typesafe-request-id' => 'req_9']),
        ]);
        $this->setConfig('retry', ['max_retries' => 0]);

        $error = null;

        try {
            TypeSafe::models()->list();
        } catch (ApiException $e) {
            $error = $e;
        }

        expect($error)->toBeInstanceOf(AuthenticationException::class)
            ->and($error->requestId)->toBe('req_9');
    });
});
