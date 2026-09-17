<?php

declare(strict_types=1);

namespace Binnash\Typesafe\DTO;

use ArrayAccess;
use Binnash\Typesafe\Exceptions\TypeSafeException;
use LogicException;

/**
 * The answers to a `systemOne` request, keyed by the question names you chose.
 *
 * Answers are also readable as properties:
 *
 * ```php
 * $answers->is_billing->noul;   // NoulResponse, or null when there is no such answer
 * $answers->noul('is_billing'); // NoulResponse, or a TypeSafeException on a type mismatch
 * ```
 *
 * @implements ArrayAccess<string, NoulResponse|ChoiceResponse|ScoreResponse>
 */
final class Answers implements ArrayAccess
{
    /**
     * @param  array<string, NoulResponse|ChoiceResponse|ScoreResponse>  $answers
     */
    private function __construct(private readonly array $answers) {}

    /**
     * Build typed answers from the `answers` map of a response body.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws TypeSafeException When an answer is missing its type or has an unknown one.
     */
    public static function fromArray(array $data): self
    {
        $answers = [];

        foreach ($data as $name => $answer) {
            $name = (string) $name;

            if (! is_array($answer) || ! is_string($answer['type'] ?? null)) {
                throw new TypeSafeException(sprintf('Answer "%s" is missing its type.', $name));
            }

            $answers[$name] = match ($answer['type']) {
                NoulResponse::TYPE => NoulResponse::fromArray($answer),
                ChoiceResponse::TYPE => ChoiceResponse::fromArray($answer),
                ScoreResponse::TYPE => ScoreResponse::fromArray($answer),
                default => throw new TypeSafeException(sprintf(
                    'Answer "%s" has an unsupported type "%s".',
                    $name,
                    $answer['type'],
                )),
            };
        }

        return new self($answers);
    }

    /**
     * Whether an answer exists under the given question name.
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->answers);
    }

    /**
     * The answer for a question name.
     *
     * @throws TypeSafeException When there is no answer under that name.
     */
    public function get(string $name): NoulResponse|ChoiceResponse|ScoreResponse
    {
        if (! $this->has($name)) {
            throw new TypeSafeException(sprintf('No answer was returned for question "%s".', $name));
        }

        return $this->answers[$name];
    }

    /**
     * The Noul answer for a question name.
     *
     * @throws TypeSafeException When the answer is missing or of another type.
     */
    public function noul(string $name): NoulResponse
    {
        return $this->typed($name, NoulResponse::class, NoulResponse::TYPE);
    }

    /**
     * The Choice answer for a question name.
     *
     * @throws TypeSafeException When the answer is missing or of another type.
     */
    public function choice(string $name): ChoiceResponse
    {
        return $this->typed($name, ChoiceResponse::class, ChoiceResponse::TYPE);
    }

    /**
     * The Score answer for a question name.
     *
     * @throws TypeSafeException When the answer is missing or of another type.
     */
    public function score(string $name): ScoreResponse
    {
        return $this->typed($name, ScoreResponse::class, ScoreResponse::TYPE);
    }

    /**
     * Every answer, keyed by question name.
     *
     * @return array<string, NoulResponse|ChoiceResponse|ScoreResponse>
     */
    public function all(): array
    {
        return $this->answers;
    }

    /**
     * The question names that were answered.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->answers);
    }

    /**
     * Read an answer as a property, or `null` when there is no such answer.
     */
    public function __get(string $name): NoulResponse|ChoiceResponse|ScoreResponse|null
    {
        return $this->answers[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    /**
     * Answers are immutable.
     */
    public function __set(string $name, mixed $value): void
    {
        throw new LogicException('Answers are immutable.');
    }

    /**
     * Answers are immutable.
     */
    public function __unset(string $name): void
    {
        throw new LogicException('Answers are immutable.');
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->has($offset);
    }

    public function offsetGet(mixed $offset): NoulResponse|ChoiceResponse|ScoreResponse|null
    {
        return is_string($offset) ? ($this->answers[$offset] ?? null) : null;
    }

    /**
     * Answers are immutable.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Answers are immutable.');
    }

    /**
     * Answers are immutable.
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Answers are immutable.');
    }

    /**
     * @param  class-string<NoulResponse|ChoiceResponse|ScoreResponse>  $class
     *
     * @throws TypeSafeException When the answer is missing or of another type.
     */
    private function typed(string $name, string $class, string $expected): NoulResponse|ChoiceResponse|ScoreResponse
    {
        $answer = $this->get($name);

        if (! $answer instanceof $class) {
            throw new TypeSafeException(sprintf(
                'Answer "%s" is a %s answer, not a %s answer.',
                $name,
                $answer->type(),
                $expected,
            ));
        }

        return $answer;
    }
}
