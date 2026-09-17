<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Questions;

use Binnash\Typesafe\Exceptions\TypeSafeException;

/**
 * A yes/no question, answered with the probability that the answer is yes.
 *
 * Use one Noul per label when several labels may apply independently.
 */
final class NoulQuestion implements QuestionInterface
{
    public const TYPE = 'noul';

    /**
     * @param  mixed  $instructions  The question as text, a JSON object or array, or `null`.
     * @param  array{true?: mixed, false?: mixed}|null  $criteria  Descriptions of the yes and no outcomes.
     *
     * @throws TypeSafeException When a criteria key is not `true` or `false`.
     */
    public function __construct(
        private readonly mixed $instructions = null,
        private readonly ?array $criteria = null,
    ) {
        $this->assertCriteriaKeys();
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
     * @return array{true?: mixed, false?: mixed}|null
     */
    public function criteria(): ?array
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

    /**
     * @throws TypeSafeException When a criteria key is not `true` or `false`.
     */
    private function assertCriteriaKeys(): void
    {
        foreach (array_keys($this->criteria ?? []) as $key) {
            if ($key !== 'true' && $key !== 'false') {
                throw new TypeSafeException(sprintf(
                    'Noul criteria keys must be "true" and/or "false"; got "%s".',
                    (string) $key,
                ));
            }
        }
    }
}
