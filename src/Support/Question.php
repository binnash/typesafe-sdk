<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Support;

use Binnash\Typesafe\Questions\ChoiceQuestion;
use Binnash\Typesafe\Questions\NoulQuestion;
use Binnash\Typesafe\Questions\ScoreQuestion;

/**
 * Factories for the three question primitives.
 *
 * This is the only supported way to build questions. The SDK deliberately ships
 * no global helper functions: reserving identifiers such as `choice()` in the
 * host application's global scope can collide with Laravel's own helpers, other
 * packages, or application code, and namespaced functions cannot be autoloaded
 * lazily. One import covers all three builders:
 *
 * ```php
 * use Binnash\Typesafe\Support\Question;
 *
 * Question::noul('Is this about billing?');
 * Question::choice('Which team?', ['billing' => null]);
 * Question::score('How urgent?', ['can wait', 'today']);
 * ```
 */
final class Question
{
    /**
     * A yes/no question, answered with the probability of yes.
     *
     * @param  mixed  $instructions  The question as text, a JSON object or array, or `null`.
     * @param  array{true?: mixed, false?: mixed}|null  $criteria  Descriptions of the yes and no outcomes.
     */
    public static function noul(mixed $instructions = null, ?array $criteria = null): NoulQuestion
    {
        return new NoulQuestion($instructions, $criteria);
    }

    /**
     * A question that selects one option from a set.
     *
     * @param  mixed  $instructions  The question as text, a JSON object or array, or `null`.
     * @param  array<string, mixed>  $criteria  Options mapped to descriptions, or `null` when undescribed.
     */
    public static function choice(mixed $instructions, array $criteria): ChoiceQuestion
    {
        return new ChoiceQuestion($instructions, $criteria);
    }

    /**
     * A question that rates the state along an ordered rubric.
     *
     * @param  mixed  $instructions  The question as text, a JSON object or array, or `null`.
     * @param  list<mixed>  $criteria  Level descriptions indexed by score from zero; at least two.
     */
    public static function score(mixed $instructions, array $criteria): ScoreQuestion
    {
        return new ScoreQuestion($instructions, $criteria);
    }
}
