<?php

declare(strict_types=1);

use Binnash\Typesafe\Support\Question;

/**
 * The README, as published to Packagist and GitHub.
 */
function readme(): string
{
    return (string) file_get_contents(__DIR__.'/../../README.md');
}

/**
 * Namespace-only mentions in prose, which are not classes and cannot be resolved.
 *
 * @var list<string>
 */
const README_NAMESPACE_MENTIONS = ['Binnash\Typesafe'];

describe('readme', function () {
    it('imports only classes that exist', function () {
        preg_match_all('/^use\s+([\\\\\w]+);/m', readme(), $matches);

        expect($matches[1])->not->toBeEmpty();

        foreach (array_unique($matches[1]) as $import) {
            expect(
                class_exists($import) || interface_exists($import) || enum_exists($import) || trait_exists($import),
            )->toBeTrue("README imports {$import}, which does not exist.");
        }
    });

    it('resolves every fully qualified class name it mentions', function () {
        preg_match_all('/(?<![\\\\\w])Binnash(?:\\\\\w+)+/', readme(), $matches);

        $resolves = fn (string $name): bool => class_exists($name)
            || interface_exists($name)
            || enum_exists($name)
            || trait_exists($name);

        $unresolved = array_values(array_filter(
            array_unique($matches[0]),
            fn (string $name): bool => ! in_array($name, README_NAMESPACE_MENTIONS, true) && ! $resolves($name),
        ));

        expect($unresolved)->toBe([], 'README mentions classes that do not exist.');
    });

    it('calls only question builders that exist', function () {
        preg_match_all('/Question::(\w+)\(/', readme(), $matches);

        expect($matches[1])->not->toBeEmpty();

        foreach (array_unique($matches[1]) as $method) {
            expect(method_exists(Question::class, $method))->toBeTrue(
                "README calls Question::{$method}(), which does not exist.",
            );
        }
    });
});
