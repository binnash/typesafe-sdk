<?php

declare(strict_types=1);

use Binnash\Typesafe\DTO\SystemOneResult;
use Binnash\Typesafe\Exceptions\ApiException;
use Binnash\Typesafe\Exceptions\AuthenticationException;
use Binnash\Typesafe\Exceptions\BadRequestException;
use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Exceptions\UnprocessableEntityException;
use Binnash\Typesafe\Retry\RetryPolicy;
use Binnash\Typesafe\Support\Question;

/*
|--------------------------------------------------------------------------
| Live API integration tests
|--------------------------------------------------------------------------
|
| These tests call https://api.typesafe.ai for real and are therefore excluded
| from the default suite (see the `unit-feature` testsuite in phpunit.xml).
| Run them with `composer test:integration` and TYPESAFE_API_KEY set; every
| test is skipped automatically when the key is missing.
|
*/

$ticket = [
    'subject' => 'Charged twice this month',
    'body' => 'I see two charges of $49 on my card for August. I only have one account. Please fix this ASAP.',
];

it('lists models', function () {
    $response = liveClient()->models->listWithResponse();
    $models = $response->data['models'];

    showLive('GET /v1/models', [
        'requestId' => $response->requestId,
        'count' => count($models),
        'first' => $models[0] ?? null,
    ]);

    expect($response->requestId)->toMatch('/^req_/')
        ->and($response->status)->toBe(200)
        ->and($models)->not->toBeEmpty();

    foreach (liveClient()->models->list() as $model) {
        expect($model->name)->toBeString()->not->toBeEmpty()
            ->and($model->description)->toBeString()
            ->and($model->releaseDate)->toBeString();
    }
})->skip(fn (): bool => liveApiKey() === null, liveSkipReason());

it('answers noul, choice, and score questions together', function () use ($ticket) {
    $response = liveClient()->systemOneWithResponse($ticket, [
        'isBilling' => Question::noul('Is this ticket about billing?'),
        'sentiment' => Question::choice("What is the customer's tone?", [
            'calm' => null,
            'frustrated' => null,
            'angry' => null,
        ]),
        'urgency' => Question::score('How urgent is this ticket?', ['can wait', 'this week', 'today']),
    ]);

    showLive('POST /v1/systemone', [
        'requestId' => $response->requestId,
        'data' => $response->data,
    ]);

    $result = SystemOneResult::fromArray($response->data, $response->requestId);
    $answers = $result->answers;

    expect($response->requestId)->toMatch('/^req_/')
        ->and($result->model)->toBeString()->not->toBeEmpty()
        ->and($result->usage->inputTokens)->toBeGreaterThan(0)
        ->and($result->usage->outputTokens)->toBeGreaterThanOrEqual(0)
        ->and($answers->isBilling->noul)->toBeGreaterThanOrEqual(0.0)
        ->and($answers->isBilling->noul)->toBeLessThanOrEqual(1.0)
        ->and(['angry', 'calm', 'frustrated'])->toContain($answers->sentiment->choice)
        ->and(array_keys($answers->sentiment->probabilities))->toHaveCount(3)
        ->and(round((float) array_sum($answers->sentiment->probabilities), 1))->toBe(1.0)
        ->and($answers->sentiment->confidence)->toBeGreaterThanOrEqual(0.0)
        ->and($answers->urgency->score)->toBeGreaterThanOrEqual(0.0)
        ->and($answers->urgency->score)->toBeLessThanOrEqual(2.0)
        ->and($answers->urgency->legend)->toBe([0 => 'can wait', 1 => 'this week', 2 => 'today'])
        ->and(array_keys($answers->urgency->probabilities))->toHaveCount(3)
        ->and(round((float) array_sum($answers->urgency->probabilities), 1))->toBe(1.0);
})->skip(fn (): bool => liveApiKey() === null, liveSkipReason());

it('accepts rich descriptions and one-sided noul criteria', function () use ($ticket) {
    $result = liveClient()->systemOne($ticket, [
        'duplicate' => Question::noul('Is the customer reporting a duplicate charge?', [
            'true' => [
                'meaning' => 'the same amount charged more than once',
                'examples' => ['billed twice'],
            ],
        ]),
        'tone' => Question::choice('Tone?', [
            'calm' => ['summary' => 'measured', 'examples' => ['please look into this']],
            'upset' => null,
        ]),
    ]);

    showLive('rich descriptions', $result->answers->all());

    expect($result->answers->duplicate->noul)->toBeGreaterThanOrEqual(0.0)
        ->and(array_keys($result->answers->tone->probabilities))->toHaveCount(2);
})->skip(fn (): bool => liveApiKey() === null, liveSkipReason());

it('rejects a bad API key with AuthenticationException', function () {
    $client = liveClient('not-a-real-key', ['retryPolicy' => new RetryPolicy(maxRetries: 0)]);

    $error = null;

    try {
        $client->models->list();
    } catch (ApiException $e) {
        $error = $e;
    }

    showLive('bad key', [
        'class' => $error === null ? null : $error::class,
        'message' => $error?->getMessage(),
    ]);

    expect($error)->toBeInstanceOf(AuthenticationException::class);
})->skip(fn (): bool => liveApiKey() === null, liveSkipReason());

it('rejects an unknown model with a readable BadRequestException', function () {
    $client = liveClient();

    $error = null;

    try {
        $client->systemOne(
            'hello',
            ['q' => Question::noul('Is this a greeting?')],
            model: 'no-such-model',
            options: ['retry' => new RetryPolicy(maxRetries: 0)],
        );
    } catch (ApiException $e) {
        $error = $e;
    }

    showLive('unknown model', [
        'class' => $error === null ? null : $error::class,
        'message' => $error?->getMessage(),
        'requestId' => $error?->requestId,
    ]);

    expect($error)->toBeInstanceOf(BadRequestException::class)
        ->and($error->getMessage())->toBe('400 Unknown model: no-such-model')
        ->and($error->requestId)->toMatch('/^req_/');
})->skip(fn (): bool => liveApiKey() === null, liveSkipReason());

it('surfaces server-side validation errors readably', function () {
    // A description type the SDK does not validate but the API rejects, to see how a 422 renders.
    $client = liveClient();

    $error = null;

    try {
        $client->systemOne(
            'x',
            ['q' => Question::score('?', [123, 'ok'])],
            options: ['retry' => new RetryPolicy(maxRetries: 0)],
        );
    } catch (ApiException $e) {
        $error = $e;
    }

    showLive('422', [
        'class' => $error === null ? null : $error::class,
        'message' => $error?->getMessage(),
    ]);

    expect($error)->toBeInstanceOf(UnprocessableEntityException::class)
        ->and($error->getMessage())->toMatch('/^422 questions\.q\.score\.criteria\.0/');
})->skip(fn (): bool => liveApiKey() === null, liveSkipReason());

it('catches shapes the API would reject before sending', function () {
    $client = liveClient();

    expect(fn () => $client->systemOne('x', []))
        ->toThrow(TypeSafeException::class, 'At least one question is required.')
        ->and(fn () => $client->systemOne('x', ['q' => Question::score('?', ['only'])]))
        ->toThrow(TypeSafeException::class, 'Score criteria must contain at least two levels; got 1.');
})->skip(fn (): bool => liveApiKey() === null, liveSkipReason());
