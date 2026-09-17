<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Tests\Support;

use Binnash\Typesafe\Laravel\TypeSafeServiceProvider;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Boots a minimal Laravel application with the TypeSafe bridge registered.
 */
abstract class LaravelTestCase extends TestbenchTestCase
{
    /** Fake transport used by the bound client, when one is queued. */
    protected ?FakeHttpClient $http = null;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TypeSafeServiceProvider::class];
    }

    /**
     * Register a fake transport so tests never touch the network.
     *
     * @param  list<ResponseInterface|Throwable>  $queue
     */
    protected function fakeTransport(array $queue = []): FakeHttpClient
    {
        $this->http = new FakeHttpClient($queue);
        $factory = new HttpFactory;

        $this->app->instance(ClientInterface::class, $this->http);
        $this->app->instance(RequestFactoryInterface::class, $factory);
        $this->app->instance(StreamFactoryInterface::class, $factory);

        return $this->http;
    }

    /**
     * Set a TypeSafe config value before the client is resolved.
     */
    protected function setConfig(string $key, mixed $value): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');
        $config->set('typesafe.'.$key, $value);
    }
}
