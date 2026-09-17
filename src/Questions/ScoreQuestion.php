<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Questions;

use Binnash\Typesafe\Exceptions\TypeSafeException;

/**
 * A question that rates the state along an ordered rubric.
 *
 * The answer is a probability-weighted position, so it can land between levels.
 */
final class ScoreQuestion implements QuestionInterface
{
    public const TYPE = 'score';

    /**
     * @param  mixed  $instructions  The question as text, a JSON object or array, or `null`.
     * @param  list<mixed>  $criteria  Level descriptions indexed by score from zero; at least two.
     *
     * @throws TypeSafeException When the criteria are a map, or hold fewer than two levels.
     */
    public function __construct(
        private readonly mixed $instructions,
        private readonly array $criteria,
    ) {
        if (! array_is_list($this->criteria)) {
            throw new TypeSafeException(
                'Score criteria must be a list of descriptions indexed by score from zero, not a map.'
            );
        }

        if (count($this->criteria) < 2) {
            throw new TypeSafeException(sprintf(
                'Score criteria must contain at least two levels; got %d.',
                count($this->criteria),
            ));
        }
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function instructions(): mixed
    {
        return $this->instructions;
    }

    /**
     * @return list<mixed>
     */
    public function criteria(): array
    {
        return $this->criteria;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'instructions' => $this->instructions,
            'criteria' => $this->criteria,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
