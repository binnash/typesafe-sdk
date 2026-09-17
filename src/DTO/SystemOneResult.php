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
     * @param  array<string, mixed>  $raw  The unmodified response body.
     */
    public function __construct(
        public readonly string $model,
        public readonly Answers $answers,
        public readonly Usage $usage,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            model: (string) ($data['model'] ?? ''),
            answers: Answers::fromArray(is_array($data['answers'] ?? null) ? $data['answers'] : []),
            usage: Usage::fromArray(is_array($data['usage'] ?? null) ? $data['usage'] : []),
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
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
