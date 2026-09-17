<?php

declare(strict_types=1);

use Binnash\Typesafe\DTO\ChoiceResponse;
use Binnash\Typesafe\DTO\NoulResponse;
use Binnash\Typesafe\DTO\ScoreResponse;
use Binnash\Typesafe\DTO\Usage;
use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Http\ApiResponse;
use Binnash\Typesafe\Tests\Support\FakeHttpClient;
use Binnash\Typesafe\TypeSafeClient;

const SYSTEM_ONE_RESPONSE = [
    'model' => 'jev-1.13.0',
    'answers' => ['q1' => ['type' => 'noul', 'noul' => 0.5]],
    'usage' => ['input_tokens' => 312, 'output_tokens' => 48],
];

/**
 * Decode the JSON body of the most recent request.
 *
 * @return array<string, mixed>
 */
function sentBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()->getBody(), true);
}

describe('requests', function () {
    it('posts the state, model, and questions', function () {
        $http = new FakeHttpClient([jsonResponse(200, SYSTEM_ONE_RESPONSE)]);
        $client = makeClient($http);

        $result = $client->systemOne(
            ['document' => 'I was charged twice. Please fix this ASAP.'],
            ['is_billing' => noul('Is this about billing?')],
        );

        $request = $http->lastRequest();

        expect($request->getMethod())->toBe('POST')
            ->and((string) $request->getUri())->toBe('https://api.test/v1/systemone')
            ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and(sentBody($http))->toBe([
                'state' => ['document' => 'I was charged twice. Please fix this ASAP.'],
                'model' => 'jev-latest',
                'questions' => [
                    'is_billing' => [
                        'type' => 'noul',
                        'instructions' => 'Is this about billing?',
                        'criteria' => null,
                    ],
                ],
            ])
            ->and($result->model)->toBe('jev-1.13.0');
    });

    it('serializes every primitive', function () {
        $http = new FakeHttpClient([jsonResponse(200, SYSTEM_ONE_RESPONSE)]);
        $client = makeClient($http);

        $client->systemOne('state', [
            'sentiment' => choice('What is the tone?', ['calm' => null, 'frustrated' => null]),
            'urgency' => score('How urgent?', ['can wait', 'this week', 'today']),
            'is_billing' => noul('Billing?', ['true' => 'About money', 'false' => 'Not about money']),
        ]);

        expect(sentBody($http)['questions'])->toBe([
            'sentiment' => [
                'type' => 'choice',
                'instructions' => 'What is the tone?',
                'criteria' => ['calm' => null, 'frustrated' => null],
            ],
            'urgency' => [
                'type' => 'score',
                'instructions' => 'How urgent?',
                'criteria' => ['can wait', 'this week', 'today'],
            ],
            'is_billing' => [
                'type' => 'noul',
                'instructions' => 'Billing?',
                'criteria' => ['true' => 'About money', 'false' => 'Not about money'],
            ],
        ]);
    });

    it('preserves null state, instructions, and criteria values', function () {
        $http = new FakeHttpClient([jsonResponse(200, SYSTEM_ONE_RESPONSE)]);

        makeClient($http)->systemOne(null, [
            'noul' => noul(null, ['true' => null, 'false' => null]),
            'choice' => choice(null, ['yes' => null, 'no' => null]),
            'score' => score(null, [null, 'high']),
        ]);

        expect(json_decode((string) $http->lastRequest()->getBody(), true))->toBe([
            'state' => null,
            'model' => 'jev-latest',
            'questions' => [
                'noul' => ['type' => 'noul', 'instructions' => null, 'criteria' => ['true' => null, 'false' => null]],
                'choice' => ['type' => 'choice', 'instructions' => null, 'criteria' => ['yes' => null, 'no' => null]],
                'score' => ['type' => 'score', 'instructions' => null, 'criteria' => [null, 'high']],
            ],
        ]);
    });

    it('honors a per-call model and the configured default', function () {
        $http = new FakeHttpClient([
            jsonResponse(200, SYSTEM_ONE_RESPONSE),
            jsonResponse(200, SYSTEM_ONE_RESPONSE),
        ]);
        $client = makeClient($http, ['defaultModel' => 'client-default']);

        $client->systemOne('s', ['q' => noul('?')]);
        $client->systemOne('s', ['q' => noul('?')], model: 'per-call');

        expect(sentBody($http)['model'])->toBe('per-call')
            ->and(json_decode((string) $http->requests[0]->getBody(), true)['model'])->toBe('client-default');
    });

    it('passes per-call headers and timeouts through', function () {
        $http = new FakeHttpClient([jsonResponse(200, SYSTEM_ONE_RESPONSE)]);

        makeClient($http)->systemOne('s', ['q' => noul('?')], options: [
            'headers' => ['X-Trace' => 'trace-1'],
            'timeout' => 3.0,
        ]);

        expect($http->lastRequest()->getHeaderLine('X-Trace'))->toBe('trace-1');
    });

    it('rejects an empty question set before sending', function () {
        $http = new FakeHttpClient([]);

        expect(fn () => makeClient($http)->systemOne('s', []))
            ->toThrow(TypeSafeException::class, 'At least one question is required.')
            ->and($http->requests)->toBe([]);
    });

    it('rejects values that are not questions', function (mixed $value, string $type) {
        $http = new FakeHttpClient([]);

        expect(fn () => makeClient($http)->systemOne('s', ['q' => $value]))
            ->toThrow(
                TypeSafeException::class,
                sprintf('Question "q" must implement Binnash\Typesafe\Questions\QuestionInterface, got %s.', $type),
            )
            ->and($http->requests)->toBe([]);
    })->with([
        'string' => ['is this billing?', 'string'],
        'array' => [['type' => 'noul'], 'array'],
        'null' => [null, 'null'],
    ]);

    it('fails clearly on an unrecognized response shape', function (mixed $wire, string $expected) {
        $http = new FakeHttpClient([jsonResponse(200, $wire)]);

        expect(fn () => makeClient($http)->systemOne('s', ['q' => noul('?')]))
            ->toThrow(TypeSafeException::class, $expected);
    })->with([
        'null' => [
            null,
            'Unexpected response shape from POST /v1/systemone; expected { answers: {...} }.',
        ],
        'no answers' => [
            ['model' => 'm'],
            'Unexpected response shape from POST /v1/systemone; expected { answers: {...} }.',
        ],
        'answers string' => [
            ['answers' => 'nope'],
            'Unexpected response shape from POST /v1/systemone; expected { answers: {...} }.',
        ],
        // A list of answers is still rejected, one answer at a time.
        'answers list' => [['answers' => ['nope']], 'Answer "0" is missing its type.'],
    ]);

    it('sends a per-call model with the request as the payload', function () {
        $http = new FakeHttpClient([jsonResponse(200, SYSTEM_ONE_RESPONSE)]);

        makeClient($http)->systemOne('s', ['q' => noul('?')], model: 'jev-1.13.0');

        expect(sentBody($http))->toBe([
            'state' => 's',
            'model' => 'jev-1.13.0',
            'questions' => ['q' => ['type' => 'noul', 'instructions' => '?', 'criteria' => null]],
        ]);
    });
});

describe('answers', function () {
    it('returns typed answers, usage, and the model', function () {
        $http = new FakeHttpClient([jsonResponse(200, [
            'model' => 'jev-1.13.0',
            'answers' => [
                'is_billing' => ['type' => 'noul', 'noul' => 0.92],
                'tone' => [
                    'type' => 'choice',
                    'choice' => 'frustrated',
                    'confidence' => 0.82,
                    'probabilities' => ['calm' => 0.05, 'frustrated' => 0.87, 'angry' => 0.08],
                ],
                'urgency' => [
                    'type' => 'score',
                    'score' => 1.6,
                    'confidence' => 0.78,
                    'legend' => ['0' => 'Calm', '1' => 'Frustrated', '2' => 'Very angry'],
                    'probabilities' => ['0' => 0.05, '1' => 0.3, '2' => 0.65],
                ],
            ],
            'usage' => ['input_tokens' => 312, 'output_tokens' => 48],
        ])]);

        $result = makeClient($http)->systemOne('state', [
            'is_billing' => noul('?'),
            'tone' => choice('?', ['calm' => null, 'frustrated' => null, 'angry' => null]),
            'urgency' => score('?', ['calm', 'frustrated', 'very angry']),
        ]);

        $answers = $result->answers;

        expect($answers->is_billing)->toBeInstanceOf(NoulResponse::class)
            ->and($answers->is_billing->noul)->toBe(0.92)
            ->and($answers->is_billing->isYes())->toBeTrue()
            ->and($answers->tone)->toBeInstanceOf(ChoiceResponse::class)
            ->and($answers->tone->choice)->toBe('frustrated')
            ->and($answers->tone->confidence)->toBe(0.82)
            ->and($answers->tone->probabilityOf('frustrated'))->toBe(0.87)
            ->and($answers->tone->probabilityOf('unknown', 0.5))->toBe(0.5)
            ->and($answers->urgency)->toBeInstanceOf(ScoreResponse::class)
            ->and($answers->urgency->score)->toBe(1.6)
            ->and($answers->urgency->levelDescription(2))->toBe('Very angry')
            ->and($answers->urgency->probabilityOf(1))->toBe(0.3)
            ->and($result->usage)->toBeInstanceOf(Usage::class)
            ->and($result->usage->inputTokens)->toBe(312)
            ->and($result->usage->outputTokens)->toBe(48)
            ->and($result->usage->totalTokens())->toBe(360);
    });

    it('supports array, method, and collection access', function () {
        $http = new FakeHttpClient([jsonResponse(200, SYSTEM_ONE_RESPONSE)]);

        $answers = makeClient($http)->systemOne('s', ['q1' => noul('?')])->answers;

        expect($answers['q1'])->toBeInstanceOf(NoulResponse::class)
            ->and($answers->get('q1'))->toBeInstanceOf(NoulResponse::class)
            ->and($answers->noul('q1')->noul)->toBe(0.5)
            ->and($answers->has('q1'))->toBeTrue()
            ->and($answers->has('nope'))->toBeFalse()
            ->and($answers['nope'])->toBeNull()
            ->and($answers->nope)->toBeNull()
            ->and(isset($answers->q1))->toBeTrue()
            ->and($answers->names())->toBe(['q1'])
            ->and($answers->all())->toHaveKey('q1');
    });

    it('reports a missing or mistyped answer', function () {
        $http = new FakeHttpClient([jsonResponse(200, SYSTEM_ONE_RESPONSE)]);
        $answers = makeClient($http)->systemOne('s', ['q1' => noul('?')])->answers;

        expect(fn () => $answers->get('missing'))
            ->toThrow(TypeSafeException::class, 'No answer was returned for question "missing".')
            ->and(fn () => $answers->choice('q1'))
            ->toThrow(TypeSafeException::class, 'Answer "q1" is a noul answer, not a choice answer.');
    });

    it('is immutable', function () {
        $http = new FakeHttpClient([jsonResponse(200, SYSTEM_ONE_RESPONSE)]);
        $answers = makeClient($http)->systemOne('s', ['q1' => noul('?')])->answers;

        $unsetOffset = function () use ($answers): void {
            unset($answers['q1']);
        };
        $setProperty = function () use ($answers): void {
            $answers->q1 = 'nope';
        };
        $unsetProperty = function () use ($answers): void {
            unset($answers->q1);
        };

        expect(fn () => $answers['q1'] = 'nope')->toThrow(LogicException::class)
            ->and($unsetOffset)->toThrow(LogicException::class)
            ->and($setProperty)->toThrow(LogicException::class)
            ->and($unsetProperty)->toThrow(LogicException::class)
            ->and($answers->q1)->toBeInstanceOf(NoulResponse::class);
    });

    it('fails clearly on an answer without a usable type', function (array $answer, string $expected) {
        $http = new FakeHttpClient([jsonResponse(200, [
            'model' => 'm',
            'answers' => ['q1' => $answer],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        expect(fn () => makeClient($http)->systemOne('s', ['q1' => noul('?')]))
            ->toThrow(TypeSafeException::class, $expected);
    })->with([
        'missing type' => [['noul' => 0.5], 'Answer "q1" is missing its type.'],
        'unknown type' => [['type' => 'mystery'], 'Answer "q1" has an unsupported type "mystery".'],
    ]);

    it('exposes the raw response body', function () {
        $http = new FakeHttpClient([jsonResponse(200, SYSTEM_ONE_RESPONSE)]);

        $result = makeClient($http)->systemOne('s', ['q1' => noul('?')]);

        expect($result->toArray())->toBe(SYSTEM_ONE_RESPONSE)
            ->and(json_encode($result))->toBe(json_encode(SYSTEM_ONE_RESPONSE))
            ->and($result->model)->toBe('jev-1.13.0');
    });

    it('defaults usage when the response omits it', function () {
        $http = new FakeHttpClient([jsonResponse(200, [
            'model' => 'm',
            'answers' => ['q1' => ['type' => 'noul', 'noul' => 0.1]],
        ])]);

        $result = makeClient($http)->systemOne('s', ['q1' => noul('?')]);

        expect($result->usage->inputTokens)->toBe(0)
            ->and($result->usage->outputTokens)->toBe(0);
    });
});

it('reports the model that answered', function () {
    $http = new FakeHttpClient([jsonResponse(200, SYSTEM_ONE_RESPONSE)]);

    expect(makeClient($http)->systemOne('s', ['q1' => noul('?')])->model)->toBe('jev-1.13.0')
        ->and(TypeSafeClient::VERSION)->toBe('0.1.0');
});

describe('response metadata', function () {
    it('exposes the request id on the result', function () {
        $http = new FakeHttpClient([
            jsonResponse(200, SYSTEM_ONE_RESPONSE, ['x-typesafe-request-id' => 'req_123']),
        ]);

        $result = makeClient($http)->systemOne('s', ['q1' => noul('?')]);

        expect($result->requestId)->toBe('req_123')
            ->and($result->toArray())->toBe(SYSTEM_ONE_RESPONSE)
            ->and(json_decode(json_encode($result), true))->toBe([
                'request_id' => 'req_123',
                'model' => 'jev-1.13.0',
                'answers' => ['q1' => ['type' => 'noul', 'noul' => 0.5]],
                'usage' => ['input_tokens' => 312, 'output_tokens' => 48],
            ]);
    });

    it('leaves the request id null when the header is absent', function () {
        $http = new FakeHttpClient([jsonResponse(200, SYSTEM_ONE_RESPONSE)]);

        $result = makeClient($http)->systemOne('s', ['q1' => noul('?')]);

        expect($result->requestId)->toBeNull()
            ->and(json_encode($result))->toBe(json_encode(SYSTEM_ONE_RESPONSE));
    });

    it('returns the raw response from systemOneWithResponse', function () {
        $wire = ['model' => 'jev-1.13.0', 'answers' => ['q1' => ['type' => 'noul', 'noul' => 0.25]], 'usage' => ['input_tokens' => 5, 'output_tokens' => 2]];
        $http = new FakeHttpClient([
            jsonResponse(200, $wire, ['x-typesafe-request-id' => 'req_9', 'x-custom' => 'yes']),
        ]);

        $response = makeClient($http)->systemOneWithResponse('s', ['q1' => noul('?')]);

        expect($response)->toBeInstanceOf(ApiResponse::class)
            ->and($response->status)->toBe(200)
            ->and($response->requestId)->toBe('req_9')
            ->and($response->data)->toBe($wire)
            ->and($response->header('x-custom'))->toBe('yes')
            ->and($http->lastRequest()->getMethod())->toBe('POST');
    });

    it('validates before sending from systemOneWithResponse too', function () {
        $http = new FakeHttpClient([]);

        expect(fn () => makeClient($http)->systemOneWithResponse('s', []))
            ->toThrow(TypeSafeException::class, 'At least one question is required.')
            ->and($http->requests)->toBe([]);
    });

    it('rejects a malformed response from systemOneWithResponse', function () {
        $http = new FakeHttpClient([jsonResponse(200, ['model' => 'm'])]);

        expect(fn () => makeClient($http)->systemOneWithResponse('s', ['q1' => noul('?')]))
            ->toThrow(TypeSafeException::class, 'Unexpected response shape from POST /v1/systemone; expected { answers: {...} }.');
    });
});
