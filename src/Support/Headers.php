<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Support;

/**
 * Case-insensitive helpers for working with PSR-7 style header maps.
 *
 * Header maps are shaped as `array<string, string|list<string>>`, matching
 * `Psr\Http\Message\MessageInterface::getHeaders()`.
 */
final class Headers
{
    /** Response header carrying the TypeSafe request identifier. */
    public const REQUEST_ID = 'x-typesafe-request-id';

    /**
     * Read the first value of a header, or `null` when it is absent.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public static function first(array $headers, string $name): ?string
    {
        $needle = strtolower($name);

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $needle) {
                continue;
            }

            $value = is_array($value) ? ($value[0] ?? null) : $value;

            return $value === null ? null : (string) $value;
        }

        return null;
    }

    /**
     * Whether a header is present in the map.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public static function has(array $headers, string $name): bool
    {
        return self::first($headers, $name) !== null;
    }

    /**
     * Read the TypeSafe request identifier from a response header map.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public static function requestId(array $headers): ?string
    {
        return self::first($headers, self::REQUEST_ID);
    }

    /**
     * Merge header maps, with last-seen casing and values winning.
     *
     * Later sources override earlier ones regardless of casing. A `null` value
     * removes the header, which lets callers reserve SDK-owned headers.
     *
     * @param  array<string, string|list<string>|null>  ...$sources
     * @return array<string, string>
     */
    public static function merge(array ...$sources): array
    {
        /** @var array<string, array{string, string}> $values */
        $values = [];

        foreach ($sources as $source) {
            foreach ($source as $name => $value) {
                $key = strtolower((string) $name);

                if ($value === null) {
                    unset($values[$key]);

                    continue;
                }

                $values[$key] = [(string) $name, is_array($value) ? (string) ($value[0] ?? '') : (string) $value];
            }
        }

        $merged = [];

        foreach ($values as [$name, $value]) {
            $merged[$name] = $value;
        }

        return $merged;
    }
}
