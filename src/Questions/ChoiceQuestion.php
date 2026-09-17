<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Questions;

use Binnash\Typesafe\Exceptions\TypeSafeException;

/**
 * A question that selects one option from a set, answered with a label and its distribution.
 */
final class ChoiceQuestion implements QuestionInterface
{
    public const TYPE = 'choice';

    /**
     * @param  mixed  $instructions  The question as text, a JSON object or array, or `null`.
     * @param  array<string, mixed>  $criteria  Options mapped to descriptions, or `null` when undescribed.
     *
     * @throws TypeSafeException When the criteria are a list, or contain no options.
     */
    public function __construct(
        private readonly mixed $instructions,
        private readonly array $criteria,
    ) {
        if ($this->criteria === []) {
            throw new TypeSafeException('Choice criteria must contain at least one option.');
        }

        if (array_is_list($this->criteria)) {
            throw new TypeSafeException(
                'Choice criteria must be a map of labels to descriptions, not a list.'
            );
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
     * @return array<string, mixed>
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
