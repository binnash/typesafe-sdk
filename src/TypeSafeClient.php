<?php

declare(strict_types=1);

namespace Binnash\Typesafe;

use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\Http\Transporter;
use Binnash\Typesafe\Resources\Models;
use JsonSerializable;

/**
 * Client for the TypeSafe AI API.
 *
 * ```php
 * $client = new TypeSafeClient(new ClientConfig('your-api-key'));
 *
 * foreach ($client->models->list() as $model) {
 *     echo $model->name;
 * }
 * ```
 */
final class TypeSafeClient implements JsonSerializable
{
    /** SDK version reported in request headers. */
    public const VERSION = '0.1.0';

    /** The models available to the account. */
    public readonly Models $models;

    /**
     * @param  ClientConfig  $config  Client settings.
     * @param  Transporter|null  $transporter  Optional transport override, mainly for tests.
     */
    public function __construct(
        public readonly ClientConfig $config,
        ?Transporter $transporter = null,
    ) {
        $this->models = new Models($transporter ?? Transporter::create($config));
    }

    /**
     * Keep credentials out of dumps, logs, and JSON encodings.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseURL' => $this->config->baseURL,
            'defaultModel' => $this->config->defaultModel,
            'timeout' => $this->config->timeout,
            'logLevel' => $this->config->logLevel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
