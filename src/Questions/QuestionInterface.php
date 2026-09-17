<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Questions;

use JsonSerializable;

/**
 * A typed question about the state, sent in the `questions` map.
 */
interface QuestionInterface extends JsonSerializable
{
    /**
     * The wire `type` of the question.
     */
    public function type(): string;

    /**
     * The question as text, a JSON object or array, or `null`.
     */
    public function instructions(): mixed;

    /**
     * The options or rubric the answer is chosen from.
     */
    public function criteria(): mixed;

    /**
     * The question as it is sent to the API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
