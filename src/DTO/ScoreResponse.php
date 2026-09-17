<?php

declare(strict_types=1);

namespace Binnash\Typesafe\DTO;

use JsonSerializable;

/**
 * A probability-weighted position along an ordered rubric.
 *
 * The score can land between levels: 1.6 on a three-level rubric is between the
 * second and third description.
 */
final class ScoreResponse implements JsonSerializable
{
    public const TYPE = 'score';

    /**
     * @param  float  $score  Expected score across the levels.
     * @param  float  $confidence  How concentrated the distribution is; not a permission to act.
     * @param  array<array-key, string>  $legend  Level numbers mapped back to their descriptions.
     * @param  array<array-key, float>  $probabilities  Every level mapped to its probability.
     */
    public function __construct(
        public readonly float $score,
        public readonly float $confidence = 0.0,
        public readonly array $legend = [],
        public readonly array $probabilities = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $legend = is_array($data['legend'] ?? null) ? $data['legend'] : [];

        return new self(
            score: (float) ($data['score'] ?? 0.0),
            confidence: (float) ($data['confidence'] ?? 0.0),
            legend: array_map(static fn (mixed $level): string => (string) $level, $legend),
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
     * Description of a level, or `null` when the level was not part of the rubric.
     */
    public function levelDescription(int|string $level): ?string
    {
        return $this->legend[$level] ?? null;
    }

    /**
     * Probability of a level, or `$default` when the level was not part of the rubric.
     */
    public function probabilityOf(int|string $level, float $default = 0.0): float
    {
        return $this->probabilities[$level] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => self::TYPE,
            'score' => $this->score,
            'confidence' => $this->confidence,
            'legend' => $this->legend,
            'probabilities' => $this->probabilities,
        ];
    }
}
