<?php

declare(strict_types=1);

use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Logging\LogLevel;

it('lists every level from most to least verbose', function () {
    expect(LogLevel::values())->toBe(['debug', 'info', 'warn', 'error', 'off']);
});

it('resolves levels from strings', function () {
    expect(LogLevel::fromString('debug'))->toBe(LogLevel::Debug)
        ->and(LogLevel::fromString(' info '))->toBe(LogLevel::Info)
        ->and(LogLevel::fromString('off'))->toBe(LogLevel::Off);
});

it('rejects unknown levels and names the accepted values', function () {
    expect(fn () => LogLevel::fromString('loud'))->toThrow(
        TypeSafeException::class,
        'Invalid log level "loud". Expected one of: debug, info, warn, error, off.',
    );
});

it('filters messages below the configured level', function () {
    $warn = LogLevel::Warn;

    expect($warn->allows(LogLevel::Debug))->toBeFalse()
        ->and($warn->allows(LogLevel::Info))->toBeFalse()
        ->and($warn->allows(LogLevel::Warn))->toBeTrue()
        ->and($warn->allows(LogLevel::Error))->toBeTrue()
        ->and(LogLevel::Off->allows(LogLevel::Error))->toBeFalse()
        ->and(LogLevel::Debug->allows(LogLevel::Debug))->toBeTrue();
});

it('ranks levels in order', function () {
    expect(LogLevel::Debug->rank())->toBe(0)
        ->and(LogLevel::Info->rank())->toBe(1)
        ->and(LogLevel::Warn->rank())->toBe(2)
        ->and(LogLevel::Error->rank())->toBe(3)
        ->and(LogLevel::Off->rank())->toBe(4);
});
