<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Resources;

use Binnash\Typesafe\DTO\ModelCard;
use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Http\ApiResponse;
use Binnash\Typesafe\Http\Transporter;

/**
 * The models available to the account.
 */
final class Models
{
    public function __construct(private readonly Transporter $transporter) {}

    /**
     * List the models and aliases accepted by the `model` field.
     *
     * @param  array{headers?: array<string, string>, timeout?: float}  $options  Per-call overrides.
     * @return list<ModelCard>
     *
     * @throws TypeSafeException The response did not match the documented shape.
     */
    public function list(array $options = []): array
    {
        $models = $this->fetch($options)->data['models'];

        return array_values(array_map(
            static fn (array $model): ModelCard => ModelCard::fromArray($model),
            $models,
        ));
    }

    /**
     * List models together with the raw response, for access to status and request ID.
     *
     * The response `data` holds the unmodified `{ "models": [...] }` payload.
     *
     * @param  array{headers?: array<string, string>, timeout?: float}  $options  Per-call overrides.
     *
     * @throws TypeSafeException The response did not match the documented shape.
     */
    public function listWithResponse(array $options = []): ApiResponse
    {
        return $this->fetch($options);
    }

    /**
     * Fetch `GET /v1/models`, validating the documented response shape.
     *
     * @param  array{headers?: array<string, string>, timeout?: float}  $options
     */
    private function fetch(array $options): ApiResponse
    {
        $response = $this->transporter->request('GET', '/v1/models', null, $options);
        $data = $response->data;

        if (! is_array($data) || ! isset($data['models']) || ! is_array($data['models']) || ! array_is_list($data['models'])) {
            throw new TypeSafeException(
                'Unexpected response shape from GET /v1/models; expected { models: [...] }.'
            );
        }

        return $response;
    }
}
