<?php

declare(strict_types=1);

use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\DTO\ModelCard;
use Binnash\Typesafe\Exceptions\ApiConnectionException;
use Binnash\Typesafe\Exceptions\ApiException;
use Binnash\Typesafe\Exceptions\ApiTimeoutException;
use Binnash\Typesafe\Exceptions\AuthenticationException;
use Binnash\Typesafe\Exceptions\InternalServerException;
use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Http\Transporter;
use Binnash\Typesafe\Retry\RetryPolicy;
use Binnash\Typesafe\Tests\Support\FakeHttpClient;
use Binnash\Typesafe\TypeSafe;
use Binnash\Typesafe\TypeSafeClient;
use GuzzleHttp\Psr7\HttpFactory;

const MODEL_WIRE = ['name' => 'jev-latest', 'description' => 'Stable release.', 'release_date' => '2026-01-01'];

describe('models.list', function () {
    it('returns typed model cards', function () {
        $http = new FakeHttpClient([jsonResponse(200, ['models' => [MODEL_WIRE]])]);
        $client = makeClient($http);

        $models = $client->models->list();

        expect($models)->toHaveCount(1)
            ->and($models[0])->toBeInstanceOf(ModelCard::class)
            ->and($models[0]->name)->toBe('jev-latest')
            ->and($models[0]->description)->toBe('Stable release.')
            ->and($models[0]->releaseDate)->toBe('2026-01-01');
    });

    it('returns an empty list when the account has no models', function () {
        $http = new FakeHttpClient([jsonResponse(200, ['models' => []])]);

        expect(makeClient($http)->models->list())->toBe([]);
    });

    it('requests the documented path and identifies the SDK', function () {
        $http = new FakeHttpClient([jsonResponse(200, ['models' => []])]);
        makeClient($http)->models->list();

        $request = $http->lastRequest();

        expect($request)->not->toBeNull()
            ->and($request->getMethod())->toBe('GET')
            ->and((string) $request->getUri())->toBe('https://api.test/v1/models')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer test-key')
            ->and($request->getHeaderLine('Accept'))->toBe('application/json')
            ->and($request->getHeaderLine('User-Agent'))->toBe('typesafe-sdk-php/'.TypeSafeClient::VERSION)
            ->and($request->getHeaderLine('X-TypeSafe-SDK'))->toBe('typesafe-sdk-php/'.TypeSafeClient::VERSION)
            ->and($request->getHeaderLine('X-TypeSafe-Runtime'))->toMatch('/^php\/\d+\.\d+\.\d+ \(\w+; \w+\)$/')
            ->and($request->getHeaderLine('Content-Type'))->toBe('')
            ->and($request->getHeaderLine('X-TypeSafe-Retry-Count'))->toBe('');
    });

    it('exposes the raw response with its request id', function () {
        $wire = ['models' => [MODEL_WIRE]];
        $http = new FakeHttpClient([
            jsonResponse(200, $wire, ['x-typesafe-request-id' => 'req_1']),
            jsonResponse(200, $wire),
        ]);
        $client = makeClient($http);

        $response = $client->models->listWithResponse();

        expect($response->status)->toBe(200)
            ->and($response->requestId)->toBe('req_1')
            ->and($response->data)->toBe($wire)
            ->and($response->header('x-typesafe-request-id'))->toBe('req_1')
            ->and($client->models->list())->toHaveCount(1);
    });

    it('fails clearly on an unrecognized response shape', function (mixed $wire) {
        $http = new FakeHttpClient([jsonResponse(200, $wire)]);

        expect(fn () => makeClient($http)->models->list())->toThrow(
            TypeSafeException::class,
            'Unexpected response shape from GET /v1/models; expected { models: [...] }.',
        );
    })->with([
        'null' => [null],
        'empty list' => [[]],
        'nested' => [['models' => ['models' => []]]],
        'null models' => [['models' => null]],
        'string models' => [['models' => 'bad']],
        'unrelated key' => [['ok' => true]],
    ]);
});

describe('configuration and headers', function () {
    it('merges default and per-call headers without clobbering auth', function () {
        $http = new FakeHttpClient([jsonResponse(200, ['models' => []])]);
        $client = makeClient($http, [
            'defaultHeaders' => ['X-Trace' => 'client', 'X-Only-Default' => 'yes', 'Authorization' => 'nope'],
        ]);

        $client->models->list(['headers' => ['X-Trace' => 'call', 'X-Only-Call' => 'yes']]);

        $request = $http->lastRequest();

        expect($request->getHeaderLine('X-Trace'))->toBe('call')
            ->and($request->getHeaderLine('X-Only-Default'))->toBe('yes')
            ->and($request->getHeaderLine('X-Only-Call'))->toBe('yes')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer test-key');
    });

    it('accepts a per-call timeout', function () {
        $http = new FakeHttpClient([jsonResponse(200, ['models' => []]), jsonResponse(200, ['models' => []]), jsonResponse(200, ['models' => []])]);
        $client = makeClient($http, ['retryPolicy' => new RetryPolicy(maxRetries: 1)]);

        expect($client->models->list(['timeout' => 2.5]))->toBe([]);
    });

    it('rejects a non-positive per-call timeout', function () {
        $http = new FakeHttpClient([jsonResponse(200, ['models' => []])]);

        expect(fn () => makeClient($http)->models->list(['timeout' => 0.0]))
            ->toThrow(TypeSafeException::class, '`timeout` must be a positive number of seconds');
    });

    it('creates a client from an API key or explicit configuration', function () {
        $fromKey = TypeSafe::client('key', ['baseURL' => 'https://x.test', 'defaultModel' => 'm']);
        $fromConfig = TypeSafe::client(new ClientConfig('key', baseURL: 'https://y.test'));

        expect($fromKey->config->apiKey)->toBe('key')
            ->and($fromKey->config->baseURL)->toBe('https://x.test')
            ->and($fromKey->config->defaultModel)->toBe('m')
            ->and($fromConfig->config->baseURL)->toBe('https://y.test');
    });

    it('never exposes the API key through debug info', function () {
        $client = TypeSafe::client('super-secret');

        expect(dumpOf($client))->not->toContain('super-secret')
            ->and(json_encode($client))->not->toContain('super-secret')
            ->and(json_decode(json_encode($client), true))->not->toHaveKey('apiKey');
    });
});

describe('errors', function () {
    it('maps a failed request to the matching exception and request id', function () {
        $http = new FakeHttpClient([
            jsonResponse(401, ['error' => ['message' => 'invalid api key']], ['x-typesafe-request-id' => 'req_9']),
        ]);

        $error = null;

        try {
            makeClient($http, ['retryPolicy' => new RetryPolicy(maxRetries: 0)])->models->list();
        } catch (ApiException $e) {
            $error = $e;
        }

        expect($error)->toBeInstanceOf(AuthenticationException::class)
            ->and($error->getMessage())->toBe('401 invalid api key')
            ->and($error->requestId)->toBe('req_9')
            ->and($error->body)->toBe(['error' => ['message' => 'invalid api key']]);
    });

    it('keeps non-JSON error bodies as text', function () {
        $http = new FakeHttpClient([textResponse(502, '<h1>bad gateway</h1>', ['content-type' => 'text/html'])]);

        $error = null;

        try {
            makeClient($http, ['retryPolicy' => new RetryPolicy(maxRetries: 0)])->models->list();
        } catch (ApiException $e) {
            $error = $e;
        }

        expect($error)->toBeInstanceOf(InternalServerException::class)
            ->and($error->body)->toBe('<h1>bad gateway</h1>')
            ->and($error->getMessage())->toBe('502 <h1>bad gateway</h1>');
    });

    it('wraps transport failures as connection errors', function () {
        $cause = new TypeError('fetch failed');
        $http = new FakeHttpClient([$cause]);

        $error = null;

        try {
            makeClient($http, ['retryPolicy' => new RetryPolicy(maxRetries: 0)])->models->list();
        } catch (ApiConnectionException $e) {
            $error = $e;
        }

        expect($error)->toBeInstanceOf(ApiConnectionException::class)
            ->and($error->getPrevious())->toBe($cause)
            ->and($error->getMessage())->toBe('Connection error: fetch failed');
    });

    it('reports a timeout when the attempt used up the timeout budget', function () {
        $http = new FakeHttpClient([new TypeError('timed out')]);
        $client = makeClient(
            $http,
            ['retryPolicy' => new RetryPolicy(maxRetries: 0), 'timeout' => 10.0],
            clock: clockFrom([100.0, 110.0]),
        );

        $error = null;

        try {
            $client->models->list();
        } catch (ApiTimeoutException $e) {
            $error = $e;
        }

        expect($error)->toBeInstanceOf(ApiTimeoutException::class)
            ->and($error->timeoutMs)->toBe(10000)
            ->and($error->getMessage())->toBe('Request timed out after 10000ms.');
    });
});

describe('retries', function () {
    it('retries retryable statuses and records the attempt header', function () {
        $http = new FakeHttpClient([
            jsonResponse(429, ['error' => 'slow down'], ['retry-after-ms' => '1500']),
            jsonResponse(503, ['error' => 'overloaded']),
            jsonResponse(200, ['models' => [MODEL_WIRE]]),
        ]);
        $delays = [];
        $client = makeClient(
            $http,
            ['retryPolicy' => new RetryPolicy(backoffJitter: 0.0)],
            recordSleeps($delays),
        );

        $models = $client->models->list();

        expect($models)->toHaveCount(1)
            ->and($http->requests)->toHaveCount(3)
            ->and($delays)->toBe([1500, 1000])
            ->and($http->requests[1]->getHeaderLine('X-TypeSafe-Retry-Count'))->toBe('1')
            ->and($http->requests[2]->getHeaderLine('X-TypeSafe-Retry-Count'))->toBe('2');
    });

    it('stops after maxRetries attempts and throws the last error', function () {
        $http = new FakeHttpClient([
            jsonResponse(429, ['error' => 'slow down']),
            jsonResponse(429, ['error' => 'slow down']),
        ]);
        $delays = [];
        $client = makeClient(
            $http,
            ['retryPolicy' => new RetryPolicy(maxRetries: 1, backoffJitter: 0.0)],
            recordSleeps($delays),
        );

        expect(fn () => $client->models->list())->toThrow(ApiException::class, '429 slow down')
            ->and($http->requests)->toHaveCount(2)
            ->and($delays)->toBe([500]);
    });

    it('does not retry statuses outside the retry policy', function () {
        $http = new FakeHttpClient([jsonResponse(422, ['detail' => 'invalid question'])]);
        $delays = [];
        $client = makeClient($http, sleeper: recordSleeps($delays));

        expect(fn () => $client->models->list())->toThrow(ApiException::class, '422 invalid question')
            ->and($http->requests)->toHaveCount(1)
            ->and($delays)->toBe([]);
    });

    it('retries connection failures', function () {
        $http = new FakeHttpClient([
            new TypeError('connection reset'),
            jsonResponse(200, ['models' => []]),
        ]);
        $delays = [];
        $client = makeClient(
            $http,
            ['retryPolicy' => new RetryPolicy(backoffJitter: 0.0)],
            recordSleeps($delays),
        );

        expect($client->models->list())->toBe([])
            ->and($delays)->toBe([500]);
    });

    it('does not retry connection failures when the policy disables it', function () {
        $http = new FakeHttpClient([new TypeError('connection reset')]);
        $client = makeClient(
            $http,
            ['retryPolicy' => new RetryPolicy(retryConnectionErrors: false)],
        );

        expect(fn () => $client->models->list())->toThrow(ApiConnectionException::class)
            ->and($http->requests)->toHaveCount(1);
    });

    it('caps the number of attempts at maxRetries plus one for transport failures', function () {
        $http = new FakeHttpClient([
            new TypeError('down'),
            new TypeError('down'),
            new TypeError('down'),
        ]);
        $delays = [];
        $client = makeClient(
            $http,
            ['retryPolicy' => new RetryPolicy(backoffJitter: 0.0)],
            recordSleeps($delays),
        );

        expect(fn () => $client->models->list())->toThrow(ApiConnectionException::class)
            ->and($http->requests)->toHaveCount(3)
            ->and($delays)->toBe([500, 1000]);
    });

    it('honors per-call retry overrides as a map', function () {
        $http = new FakeHttpClient([jsonResponse(429, ['error' => 'slow down'])]);
        $delays = [];
        $client = makeClient($http, sleeper: recordSleeps($delays));

        expect(fn () => $client->models->list(['retry' => ['maxRetries' => 0]]))
            ->toThrow(ApiException::class, '429 slow down')
            ->and($http->requests)->toHaveCount(1)
            ->and($delays)->toBe([]);
    });

    it('honors per-call retry overrides as a policy', function () {
        $http = new FakeHttpClient([jsonResponse(503, ['error' => 'overloaded'])]);
        $client = makeClient($http);

        expect(fn () => $client->models->list(['retry' => new RetryPolicy(maxRetries: 0)]))
            ->toThrow(ApiException::class, '503 overloaded')
            ->and($http->requests)->toHaveCount(1);
    });

    it('inherits unset settings from the client policy for per-call overrides', function () {
        $http = new FakeHttpClient([
            jsonResponse(503, ['error' => 'overloaded']),
            jsonResponse(200, ['models' => []]),
        ]);
        $delays = [];
        $client = makeClient(
            $http,
            ['retryPolicy' => new RetryPolicy(backoffInitialMs: 250, backoffJitter: 0.0)],
            recordSleeps($delays),
        );

        expect($client->models->list(['retry' => ['maxRetries' => 1]]))->toBe([])
            ->and($delays)->toBe([250]);
    });

    it('rejects malformed per-call retry overrides without sending', function () {
        $http = new FakeHttpClient([jsonResponse(200, ['models' => []])]);

        expect(fn () => makeClient($http)->models->list(['retry' => ['nope' => 1]]))
            ->toThrow(TypeSafeException::class, 'Invalid retry options: Unknown named parameter $nope')
            ->and($http->requests)->toBe([]);
    });
});

describe('transport construction', function () {
    it('discovers a client and factories from installed packages', function () {
        $transporter = Transporter::create(new ClientConfig('key'));

        expect($transporter)->toBeInstanceOf(Transporter::class);
    });

    it('prefers configured services over discovery', function () {
        $http = new FakeHttpClient([jsonResponse(200, ['models' => [MODEL_WIRE]])]);
        $factory = new HttpFactory;
        $client = new TypeSafeClient(new ClientConfig(
            apiKey: 'key',
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        ));

        expect($client->models->list())->toHaveCount(1);
    });

    it('pairs a configured client with discovered factories', function () {
        $http = new FakeHttpClient([jsonResponse(200, ['models' => [MODEL_WIRE]])]);
        $client = new TypeSafeClient(new ClientConfig(apiKey: 'key', httpClient: $http));

        expect($client->models->list())->toHaveCount(1);
    });
});
