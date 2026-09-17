<?php

declare(strict_types=1);

use Binnash\Typesafe\Logging\HeaderRedactor;

it('masks bearer tokens but keeps the scheme and last four characters', function () {
    expect(HeaderRedactor::mask('Bearer sk-abcdefghijklmnop'))->toBe('Bearer ***mnop')
        ->and(HeaderRedactor::mask('sk-abcdefghijklmnop'))->toBe('***mnop');
});

it('hides short secrets entirely', function () {
    expect(HeaderRedactor::mask('Bearer short'))->toBe('Bearer ***')
        ->and(HeaderRedactor::mask('tiny'))->toBe('***')
        ->and(HeaderRedactor::mask('12345678'))->toBe('***');
});

it('redacts credential headers and cookies', function () {
    $headers = [
        'Authorization' => 'Bearer sk-abcdefghijklmnop',
        'x-api-key' => 'sk-abcdefghijklmnop',
        'Proxy-Authorization' => 'Basic dXNlcjpwYXNzd29yZA==',
        'Cookie' => 'session=abc123',
        'Set-Cookie' => ['a=1', 'b=2'],
        'X-Trace' => 'trace-123',
    ];

    expect(HeaderRedactor::redact($headers))->toBe([
        'Authorization' => 'Bearer ***mnop',
        'x-api-key' => '***mnop',
        'Proxy-Authorization' => 'Basic ***ZA==',
        'Cookie' => '***',
        'Set-Cookie' => ['***', '***'],
        'X-Trace' => 'trace-123',
    ]);
});

it('leaves unrelated headers untouched', function (string $name) {
    expect(HeaderRedactor::redactValue($name, 'value'))->toBe('value');
})->with([
    'accept' => ['Accept'],
    'content type' => ['Content-Type'],
    'retry count' => ['X-TypeSafe-Retry-Count'],
]);

it('reports the sensitive header names', function () {
    expect(HeaderRedactor::sensitiveHeaders())->toBe([
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'cookie',
        'set-cookie',
    ]);
});
