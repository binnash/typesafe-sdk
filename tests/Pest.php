<?php

declare(strict_types=1);

use Binnash\Typesafe\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Capture `var_dump()` output for an object, which honors `__debugInfo()`.
 */
function dumpOf(object $value): string
{
    ob_start();
    var_dump($value);

    return (string) ob_get_clean();
}
