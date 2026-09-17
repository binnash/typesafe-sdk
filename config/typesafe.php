<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| TypeSafe Configuration
|--------------------------------------------------------------------------
|
| Configuration for the TypeSafe AI SDK when it is used inside Laravel. The
| core SDK never reads the environment itself; this file is the single place
| where environment variables are resolved into client settings.
|
| Publish this file to customise it:
|
|     php artisan vendor:publish --tag=typesafe-config
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | Create a key in the TypeSafe console: https://console.typesafe.ai/
    | Requests fail with a 401 when this is missing or invalid.
    |
    */

    'api_key' => env('TYPESAFE_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | The API root, without a trailing slash.
    |
    */

    'base_url' => env('TYPESAFE_BASE_URL', 'https://api.typesafe.ai'),

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    |
    | Model used when a call does not name one. `jev-latest` tracks the most
    | recent stable release and moves when a new one ships; pin a versioned ID,
    | such as `jev-1.13.0`, when you have tuned thresholds against it.
    |
    */

    'default_model' => env('TYPESAFE_DEFAULT_MODEL', 'jev-latest'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout per attempt, in seconds. There is no total budget across retries.
    | Configure a real transport timeout on your PSR-18 client as well.
    |
    */

    'timeout' => (float) env('TYPESAFE_TIMEOUT', 10.0),

    /*
    |--------------------------------------------------------------------------
    | Log Level
    |--------------------------------------------------------------------------
    |
    | One of: debug, info, warn, error, off. `info` logs request summaries;
    | `debug` also logs headers and bodies. Request bodies are not redacted, so
    | avoid `debug` when the state you send contains sensitive data.
    |
    */

    'log_level' => env('TYPESAFE_LOG_LEVEL', 'warn'),

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    |
    | Retries use capped exponential backoff with jitter. A per-call override can
    | be passed as the `retry` option on any request.
    |
    */

    'retry' => [

        // Retries after the initial attempt; 0 disables retries.
        'max_retries' => (int) env('TYPESAFE_MAX_RETRIES', 2),

        // First backoff delay in milliseconds, doubled on each subsequent attempt.
        'backoff_initial_ms' => (int) env('TYPESAFE_BACKOFF_INITIAL_MS', 500),

        // Upper bound for a single backoff delay in milliseconds.
        'backoff_max_ms' => (int) env('TYPESAFE_BACKOFF_MAX_MS', 5000),

        // Fraction of each backoff delay randomly subtracted, from 0 to 1.
        'backoff_jitter' => (float) env('TYPESAFE_BACKOFF_JITTER', 0.25),

        // Honor `retry-after-ms` and `Retry-After` delays returned by the API.
        'respect_retry_after' => (bool) env('TYPESAFE_RESPECT_RETRY_AFTER', true),

    ],

    /*
    |--------------------------------------------------------------------------
    | Logger
    |--------------------------------------------------------------------------
    |
    | PSR-3 logger used by the SDK. Leave null to send SDK logs to Laravel's
    | own logger, honoring `log_level` below.
    |
    */

    'logger' => null,

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    |
    | Optional PSR-18/PSR-17 overrides. Leave these null to let the SDK discover
    | an installed implementation; bind them in a service provider when your
    | application needs its own client, middleware, or transport timeouts.
    |
    */

    'http_client' => null,

    'request_factory' => null,

    'stream_factory' => null,

];
