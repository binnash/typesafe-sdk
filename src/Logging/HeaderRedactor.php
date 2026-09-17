<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Logging;

/**
 * Redact credentials before request headers or bodies reach a logger.
 */
final class HeaderRedactor
{
    /** Placeholder used for fully redacted values. */
    public const MASK = '***';

    /**
     * Headers that keep a key suffix so logs stay identifiable.
     *
     * @var list<string>
     */
    private const KEY_HEADERS = ['authorization', 'proxy-authorization', 'x-api-key'];

    /**
     * Headers whose values are replaced entirely.
     *
     * @var list<string>
     */
    private const OPAQUE_HEADERS = ['cookie', 'set-cookie'];

    /**
     * Header names whose values are credentials.
     *
     * @return list<string>
     */
    public static function sensitiveHeaders(): array
    {
        return [...self::KEY_HEADERS, ...self::OPAQUE_HEADERS];
    }

    /**
     * Copy a header map with credential values redacted.
     *
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string|list<string>>
     */
    public static function redact(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $value) {
            $redacted[$name] = is_array($value)
                ? array_map(fn (string $item): string => self::redactValue((string) $name, $item), $value)
                : self::redactValue((string) $name, $value);
        }

        return $redacted;
    }

    /**
     * Redact a single header value according to its header name.
     */
    public static function redactValue(string $name, string $value): string
    {
        $lower = strtolower($name);

        if (in_array($lower, self::KEY_HEADERS, true)) {
            return self::mask($value);
        }

        if (in_array($lower, self::OPAQUE_HEADERS, true)) {
            return self::MASK;
        }

        return $value;
    }

    /**
     * Mask a secret, preserving an optional scheme and the last four characters.
     */
    public static function mask(string $value): string
    {
        $parts = preg_split('/\s+/', $value, 2) ?: [];
        $scheme = count($parts) === 2 ? $parts[0] : null;
        $secret = count($parts) === 2 ? $parts[1] : $value;

        $tail = $secret !== '' && strlen($secret) > 8 ? substr($secret, -4) : '';

        return ($scheme === null ? '' : $scheme.' ').self::MASK.$tail;
    }
}
