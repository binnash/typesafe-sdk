<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Laravel\Facades;

use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\DTO\SystemOneResult;
use Binnash\Typesafe\Http\ApiResponse;
use Binnash\Typesafe\Laravel\TypeSafeServiceProvider;
use Binnash\Typesafe\Resources\Models;
use Binnash\Typesafe\TypeSafeClient;
use Illuminate\Support\Facades\Facade;

/**
 * Static access to the shared TypeSafe client.
 *
 * ```php
 * $result = TypeSafe::systemOne($state, ['tone' => choice('Tone?', ['calm' => null])]);
 * $models = TypeSafe::models()->list();
 * ```
 *
 * @method static SystemOneResult systemOne(mixed $state, array<string, \Binnash\Typesafe\Questions\QuestionInterface> $questions, ?string $model = null, array{headers?: array<string, string>, timeout?: float, retry?: \Binnash\Typesafe\Retry\RetryPolicy|array<string, mixed>} $options = [], array<string, mixed> $extra = [])
 * @method static ApiResponse systemOneWithResponse(mixed $state, array<string, \Binnash\Typesafe\Questions\QuestionInterface> $questions, ?string $model = null, array{headers?: array<string, string>, timeout?: float, retry?: \Binnash\Typesafe\Retry\RetryPolicy|array<string, mixed>} $options = [], array<string, mixed> $extra = [])
 * @method static Models models()
 *
 * @see TypeSafeClient
 */
final class TypeSafe extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TypeSafeServiceProvider::ALIAS;
    }

    /**
     * The client's configuration, for inspecting resolved settings.
     */
    public static function config(): ClientConfig
    {
        /** @var ClientConfig $config */
        $config = self::$app->make(ClientConfig::class);

        return $config;
    }
}
