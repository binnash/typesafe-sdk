<?php

declare(strict_types=1);

namespace Binnash\Typesafe\DTO;

use JsonSerializable;

/**
 * A selected option with the full probability distribution behind it.
 */
final class ChoiceResponse implements JsonSerializable
{
    public const TYPE = 'choice';

    /**
     * @param  string  $choice  The highest-probability option.
     * @param  float  $confidence  How concentrated the distribution is; not a permission to act.
     * @param  array<array-key, float>  $probabilities  Every option mapped to its probability.
     */
    public function __construct(
        public readonly string $choice,
        public readonly float $confidence = 0.0,
        public readonly array $probabilities = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            choice: (string) ($data['choice'] ?? ''),
            confidence: (float) ($data['confidence'] ?? 0.0),
            probabilities: array_map(
                static fn (mixed $probability): float => (float) $probability,
                is_array($data['probabilities'] ?? null) ? $data['probabilities'] : [],
            ),
        );
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * Probability of a label, or `$default` when the label was not offered.
     */
    public function probabilityOf(string $label, float $default = 0.0): float
    {
        return $this->probabilities[$label] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => self::TYPE,
            'choice' => $this->choice,
            'confidence' => $this->confidence,
            'probabilities' => $this->probabilities,
        ];
    }
}
