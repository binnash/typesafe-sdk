# TypeSafe PHP SDK

PHP SDK for [TypeSafe AI](https://typesafe.ai) — small units of AI intelligence you
can use like programming primitives.

TypeSafe's **System One** models, including **Jev**, turn natural language and
application state into typed judgments and probabilities that code can combine. A
single request evaluates any number of typed questions about the same state, and
your code owns the workflow: routing, thresholds, ranking, and side effects.

- [Requirements](#requirements)
- [Installation](#installation)
- [Quickstart](#quickstart)
- [Primitives](#primitives)
- [Questions](#questions)
- [Reading answers](#reading-answers)
- [Configuration](#configuration)
- [Per-call options](#per-call-options)
- [Retries](#retries)
- [Errors](#errors)
- [Logging](#logging)
- [Models](#models)
- [Testing](#testing)

## Requirements

- PHP 8.2 or newer with `ext-json`
- A PSR-18 HTTP client and PSR-17 request/stream factories. The SDK discovers
  whatever your project already has installed; `guzzlehttp/guzzle` is a good default:

```sh
composer require guzzlehttp/guzzle
```

## Installation

```sh
composer require binnash/typesafe-sdk
```

Create an API key in the [TypeSafe console](https://console.typesafe.ai/), then
construct a client. The SDK never reads the environment; pass values explicitly so
configuration stays in your application's control.

```php
use Binnash\Typesafe\TypeSafe;

$client = TypeSafe::client('your-api-key');
```

## Quickstart

```php
use Binnash\Typesafe\TypeSafe;

$client = TypeSafe::client('your-api-key');

$result = $client->systemOne(
    state: [
        'subject' => 'Charged twice this month',
        'body' => 'I see two charges of $49 on my card. Please fix this ASAP.',
    ],
    questions: [
        'is_billing' => noul('Is this ticket about billing?'),
        'tone' => choice("What is the customer's tone?", [
            'calm' => null,
            'frustrated' => null,
            'angry' => null,
        ]),
        'urgency' => score('How urgent is this ticket?', ['can wait', 'this week', 'today']),
    ],
);

$result->answers->is_billing->noul;               // 0.94
$result->answers->tone->choice;                   // "frustrated"
$result->answers->urgency->score;                 // 1.6, may land between levels
$result->usage->inputTokens;                      // 312
$result->requestId;                               // "req_..." for support and tracing
```

`state` is the content to evaluate: a string, or structured data such as a record
or chat log. `questions` is a map you name; answers come back under the same names.
Question names are keys in your code only — they are not sent to the model, so put
the full meaning in the question itself.

## Primitives

Choose by what the answer means.

| Question | Use when | Answer |
| --- | --- | --- |
| `noul()` | A condition holds or not | Probability of yes, `0.0`–`1.0` |
| `choice()` | One of a defined set | Winning label, distribution, confidence |
| `score()` | Degree along an ordered rubric | Probability-weighted score, legend, confidence |

Use one `noul()` per label when several labels may apply independently. Use
comparable `score()` questions for graded ranking across items.

## Questions

### Noul — yes or no

```php
noul('Does this convey urgency?');

noul('Does this convey urgency?', [
    'true' => 'Explicitly time-sensitive',
    'false' => 'No urgency expressed',
]);
```

A probability near `0.5` means yes and no are similarly likely — not medium
intensity. There is no separate confidence.

```php
$answer = $result->answers->is_urgent;
$answer->noul;            // 0.92
$answer->isYes();         // true, using the default 0.5 threshold
$answer->isYes(0.9);      // true, using your own threshold
```

### Choice — one of a set

```php
choice('Which team should handle this?', [
    'billing' => 'Payments, invoicing, refunds',
    'technical' => 'Bugs, outages, integrations',
    'sales' => null, // null when the label needs no extra detail
]);
```

```php
$answer = $result->answers->department;
$answer->choice;                            // "technical"
$answer->confidence;                        // 0.82
$answer->probabilities;                     // ['billing' => 0.08, 'technical' => 0.85, 'sales' => 0.07]
$answer->probabilityOf('technical');        // 0.85
```

Confidence summarizes how concentrated the distribution is. It is not a
guarantee of correctness and not permission to act; validate thresholds on your
own data and consequences.

### Score — degree along a rubric

```php
score('How frustrated is the customer?', ['Calm', 'Frustrated', 'Very angry']);
```

At least two levels are required, ordered from zero. Levels should describe
concrete situations and stand on their own.

```php
$answer = $result->answers->frustration;
$answer->score;                             // 1.6 — between "Frustrated" and "Very angry"
$answer->legend;                            // [0 => 'Calm', 1 => 'Frustrated', 2 => 'Very angry']
$answer->levelDescription(2);               // "Very angry"
$answer->probabilityOf(1);                  // 0.3
$answer->confidence;                        // 0.78
```

### Instructions and criteria

Instructions may be text, or a JSON object or array when a question needs
structure. Descriptions may also be objects, which helps define contrasts,
exclusions, and examples:

```php
noul('Is the customer reporting a duplicate charge?', [
    'true' => [
        'meaning' => 'the same amount charged more than once',
        'examples' => ['billed twice'],
    ],
]);

choice('Are these the same incident?', [
    'same' => ['match_on' => 'actors, action, location, occurrence time'],
    'different' => ['distinguish_by' => 'occurrence time or distinguishing numbers'],
]);
```

### Composing requests

Ask independent questions over the same state together; they run in parallel and
cannot see one another's answers. Include speculative questions when useful — code
consumes only the applicable answers. A second request is warranted only when an
earlier answer determines what to fetch or ask next.

## Reading answers

Answers are keyed by the names you chose and support property, array, and typed
access:

```php
$answers = $result->answers;

$answers->tone;               // ChoiceResponse|null
$answers['tone'];             // ChoiceResponse|null
$answers->has('tone');        // true
$answers->get('tone');        // throws when the answer is missing
$answers->names();            // ['is_billing', 'tone', 'urgency']
$answers->all();              // every answer as an array

$answers->noul('is_billing'); // NoulResponse, throws on a type mismatch
$answers->choice('tone');     // ChoiceResponse
$answers->score('urgency');   // ScoreResponse
```

Answers are immutable. `SystemOneResult` also carries `model`, `usage`, and
`requestId`, and `json_encode($result)` produces the response body plus
`request_id`.

## Configuration

Use `ClientConfig` when you need anything beyond an API key:

```php
use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\Retry\RetryPolicy;
use Binnash\Typesafe\TypeSafeClient;

$config = new ClientConfig(
    apiKey: 'your-api-key',
    baseURL: 'https://api.typesafe.ai',
    defaultModel: 'jev-latest',
    timeout: 10.0,                       // seconds, per attempt
    retryPolicy: new RetryPolicy(maxRetries: 2),
    logger: $psr3Logger,                 // optional PSR-3 logger
    logLevel: 'warn',                    // debug | info | warn | error | off
    defaultHeaders: ['X-Team' => 'support'],
    httpClient: $psr18Client,            // optional overrides; discovered otherwise
    requestFactory: $psr17RequestFactory,
    streamFactory: $psr17StreamFactory,
);

$client = new TypeSafeClient($config);
```

`ClientConfig::make()` accepts an API key string or a map of the same arguments,
and `TypeSafe::client('key', ['timeout' => 30.0])` is shorthand for it. The API
key is always redacted from `var_dump()` and `json_encode()` output.

Per-request headers are merged case-insensitively, and SDK-owned headers
(`Authorization`, `Accept`, `User-Agent`, `X-TypeSafe-SDK`, `X-TypeSafe-Runtime`,
`Content-Type`, `X-TypeSafe-Retry-Count`) cannot be overridden.

## Per-call options

Every call accepts an options array and, for `systemOne`, extra top-level payload
fields:

```php
$result = $client->systemOne(
    state: $ticket,
    questions: ['tone' => choice('Tone?', ['calm' => null, 'upset' => null])],
    model: 'jev-1.13.0',                                  // pin a version
    options: [
        'headers' => ['X-Trace' => $request->id()],
        'timeout' => 30.0,
        'retry' => ['maxRetries' => 0],                   // or a RetryPolicy instance
    ],
    extra: ['future_option' => null],                     // forwarded, never overriding state/model/questions
);
```

Use `systemOneWithResponse()` when you also need the HTTP status, headers, or
request ID (the JavaScript SDK's `withResponse()`):

```php
$response = $client->systemOneWithResponse($state, $questions);

$response->status;                 // 200
$response->requestId;              // "req_..."
$response->header('retry-after');  // null when absent
$response->data;                   // raw response body
```

## Retries

Failures are retried automatically with capped exponential backoff and jitter.

| Setting | Default | Meaning |
| --- | --- | --- |
| `maxRetries` | `2` | Retries after the initial attempt; `0` disables |
| `backoffInitialMs` | `500` | First backoff delay |
| `backoffMaxMs` | `5000` | Maximum backoff delay |
| `backoffJitter` | `0.25` | Fraction of each delay randomly subtracted |
| `httpStatuses` | `408`, `429`, `500`–`599` | Status codes to retry |
| `respectRetryAfter` | `true` | Honor `retry-after-ms` and `Retry-After` headers |
| `maxRetryAfterMs` | `60000` | Longest server delay to honor before using backoff |
| `retryConnectionErrors` | `true` | Retry connection failures |
| `retryTimeoutErrors` | `true` | Retry attempts that exceeded the timeout |

```php
use Binnash\Typesafe\Retry\RetryPolicy;

$policy = new RetryPolicy(maxRetries: 4, backoffInitialMs: 250);

$client = TypeSafe::client('key', ['retryPolicy' => $policy]);
$limited = $policy->with(maxRetries: 0);           // a copy with one setting changed

$client->models->list(['retry' => ['maxRetries' => 0]]);   // per call
```

A per-call timeout is enforced per attempt: the SDK measures each attempt and
raises `ApiTimeoutException` when the budget is spent. Configure real transport
timeouts on your PSR-18 client as well (for example Guzzle's `timeout` option).

## Errors

Every exception extends `Binnash\Typesafe\Exceptions\TypeSafeException`.

| Exception | Raised for |
| --- | --- |
| `ApiException` | Any other non-2xx response; exposes `status`, `body`, `headers`, `requestId` |
| `BadRequestException` | `400` — the request is invalid |
| `AuthenticationException` | `401` — the API key is missing or invalid |
| `PermissionDeniedException` | `403` — access is denied |
| `NotFoundException` | `404` |
| `UnprocessableEntityException` | `422` — request validation failed |
| `RateLimitException` | `429` — exposes `retryAfterMs` |
| `InternalServerException` | `5xx` |
| `ApiConnectionException` | The request or response delivery failed |
| `ApiTimeoutException` | The attempt exceeded the timeout; exposes `timeoutMs` |

Messages are derived from the response body, including FastAPI/Pydantic
validation details:

```php
use Binnash\Typesafe\Exceptions\ApiException;
use Binnash\Typesafe\Exceptions\RateLimitException;

try {
    $client->models->list();
} catch (RateLimitException $e) {
    $e->retryAfterMs;                    // 2500, when the server asked for a delay
} catch (ApiException $e) {
    $e->status;                          // 422
    $e->requestId;                       // "req_..."
    $e->getMessage();                    // '422 questions.q.score.criteria.0: Input should be a valid string'
    $e->body;                            // the decoded response body
}
```

Shapes the API would reject are caught before sending, so failures surface
locally: an empty `questions` map, `score()` criteria that are not a list of at
least two levels, `choice()` criteria that are a list or empty, and unknown
`noul()` criteria keys.

## Logging

Pass any PSR-3 logger. `info` logs request summaries; `debug` adds headers and
bodies. Credential headers are redacted before they reach the logger.

```php
use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\TypeSafeClient;

$client = new TypeSafeClient(new ClientConfig(
    apiKey: 'your-api-key',
    logger: $logger,  // any PSR-3 logger, such as Monolog
    logLevel: 'info', // debug | info | warn | error | off
));
```

Redaction covers `Authorization`, `Proxy-Authorization`, `X-API-Key` (scheme and
last four characters preserved), and `Cookie`/`Set-Cookie` (replaced entirely).
Request bodies are **not** redacted — avoid logging `debug` when `state` contains
sensitive data.

## Models

```php
foreach ($client->models->list() as $model) {
    echo $model->name, ' ', $model->releaseDate, PHP_EOL;
    $model->description;
}

$response = $client->models->listWithResponse();
$response->requestId;
$response->data;                       // the raw { "models": [...] } payload
```

`jev-latest` tracks the most recent stable release and moves when a new one
ships, so answers can change without a change on your side. The response's
`model` field reports the versioned ID that answered — log it, and pin a version
in `defaultModel` when you have tuned thresholds against it.

## Testing

```sh
composer test              # unit and feature tests, no network access
composer test:integration  # live API tests; skipped without TYPESAFE_API_KEY
composer pint              # code style
```

The live suite calls `https://api.typesafe.ai` for real. Set `TYPESAFE_API_KEY`
to run it, and optionally `TYPESAFE_BASE_URL` to point at another host. Tests are
skipped automatically when no key is present, and are excluded from
`composer test`.
