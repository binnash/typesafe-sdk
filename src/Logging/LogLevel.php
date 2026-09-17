<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Logging;

use Binnash\Typesafe\Exceptions\TypeSafeException;

/**
 * Log verbosity, from most to least verbose.
 *
 * `Off` disables logging entirely.
 */
enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
    case Off = 'off';

    /** The default log level used when none is configured. */
    public const DEFAULT = self::Warn;

    /**
     * Resolve a log level from its string value.
     *
     * @throws TypeSafeException When the value is not a known log level.
     */
    public static function fromString(string $value): self
    {
        return self::tryFrom(trim($value)) ?? throw new TypeSafeException(sprintf(
            'Invalid log level "%s". Expected one of: %s.',
            $value,
            implode(', ', self::values()),
        ));
    }

    /**
     * Whether a logger configured at this level accepts a message at the given level.
     */
    public function allows(self $level): bool
    {
        return $level->rank() >= $this->rank();
    }

    /**
     * Sort order, from most verbose (`debug`) to least (`off`).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Warn => 2,
            self::Error => 3,
            self::Off => 4,
        };
    }

    /**
     * Every supported log level, from most to least verbose.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }
}
