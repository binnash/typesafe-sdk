<?php

declare(strict_types=1);

namespace Binnash\Typesafe;

use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\Exceptions\TypeSafeException;

/**
 * Entry point for creating a client.
 *
 * ```php
 * $client = TypeSafe::client('your-api-key');
 * ```
 */
final class TypeSafe
{
    /**
     * Create a client from an API key or an explicit configuration.
     *
     * @param  string|ClientConfig  $config  API key, or a fully resolved configuration.
     * @param  array<string, mixed>  $options  Additional configuration, used with a string API key.
     *
     * @throws TypeSafeException When required configuration is missing or invalid.
     */
    public static function client(string|ClientConfig $config, array $options = []): TypeSafeClient
    {
        if (is_string($config)) {
            return new TypeSafeClient(ClientConfig::make(['apiKey' => $config] + $options));
        }

        return new TypeSafeClient($config);
    }
}
