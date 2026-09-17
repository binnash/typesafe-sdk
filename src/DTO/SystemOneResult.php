<?php

declare(strict_types=1);

namespace Binnash\Typesafe\DTO;

use JsonSerializable;

/**
 * The answers to a `systemOne` request, with the model that answered and token usage.
 */
final class SystemOneResult implements JsonSerializable
{
    /**
     * @param  string  $model  The model that performed the evaluation; versioned, so it can be logged.
     * @param  Answers  $answers  One answer per question, keyed by the names you chose.
     * @param  Usage  $usage  Token usage for the request.
     * @param  string|null  $requestId  Value of `x-typesafe-request-id`, for tracing and support.
     * @param  array<string, mixed>  $raw  The unmodified response body.
     */
    public function __construct(
        public readonly string $model,
        public readonly Answers $answers,
        public readonly Usage $usage,
        public readonly ?string $requestId = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $requestId = null): self
    {
        return new self(
            model: (string) ($data['model'] ?? ''),
            answers: Answers::fromArray(is_array($data['answers'] ?? null) ? $data['answers'] : []),
            usage: Usage::fromArray(is_array($data['usage'] ?? null) ? $data['usage'] : []),
            requestId: $requestId,
            raw: $data,
        );
    }

    /**
     * The unmodified response body.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * The response body with `request_id` merged in when the API supplied one.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->requestId === null
            ? $this->raw
            : ['request_id' => $this->requestId] + $this->raw;
    }
}
