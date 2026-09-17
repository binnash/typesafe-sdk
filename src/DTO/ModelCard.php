<?php

declare(strict_types=1);

namespace Binnash\Typesafe\DTO;

use JsonSerializable;

/**
 * A model or alias the account can name in the `model` field.
 */
final class ModelCard implements JsonSerializable
{
    /**
     * @param  string  $name  Model ID or alias, as accepted by the `model` field.
     * @param  string  $description  What the model is for.
     * @param  string  $releaseDate  When the model or alias was released.
     * @param  array<string, mixed>  $raw  The unmodified wire entry, including undocumented fields.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $releaseDate,
        public readonly array $raw = [],
    ) {}

    /**
     * Build a card from a `GET /v1/models` entry.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            releaseDate: (string) ($data['release_date'] ?? ''),
            raw: $data,
        );
    }

    /**
     * The unmodified wire entry.
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
