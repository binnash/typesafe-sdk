<?php

declare(strict_types=1);

namespace Binnash\Typesafe\DTO;

use JsonSerializable;

/**
 * A yes/no answer: the probability that the answer is yes.
 *
 * There is no separate confidence; treat values near 0.5 as an unclear answer
 * rather than as an intensity.
 */
final class NoulResponse implements JsonSerializable
{
    public const TYPE = 'noul';

    public function __construct(public readonly float $noul) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((float) ($data['noul'] ?? 0.0));
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * Whether the probability of yes reaches the threshold.
     */
    public function isYes(float $threshold = 0.5): bool
    {
        return $this->noul >= $threshold;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['type' => self::TYPE, 'noul' => $this->noul];
    }
}
