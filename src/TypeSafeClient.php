<?php

declare(strict_types=1);

namespace Binnash\Typesafe;

use Binnash\Typesafe\Config\ClientConfig;
use Binnash\Typesafe\DTO\SystemOneResult;
use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Http\Transporter;
use Binnash\Typesafe\Questions\QuestionInterface;
use Binnash\Typesafe\Resources\Models;
use JsonSerializable;

/**
 * Client for the TypeSafe AI API.
 *
 * ```php
 * $client = new TypeSafeClient(new ClientConfig('your-api-key'));
 *
 * foreach ($client->models->list() as $model) {
 *     echo $model->name;
 * }
 * ```
 */
final class TypeSafeClient implements JsonSerializable
{
    /** SDK version reported in request headers. */
    public const VERSION = '0.1.0';

    /** The models available to the account. */
    public readonly Models $models;

    private readonly Transporter $transporter;

    /**
     * @param  ClientConfig  $config  Client settings.
     * @param  Transporter|null  $transporter  Optional transport override, mainly for tests.
     */
    public function __construct(
        public readonly ClientConfig $config,
        ?Transporter $transporter = null,
    ) {
        $this->transporter = $transporter ?? Transporter::create($config);
        $this->models = new Models($this->transporter);
    }

    /**
     * Answer named questions about text or structured state.
     *
     * Ask independent questions over the same state together: they run in parallel and
     * cannot see one another's answers. A second request is only needed when an earlier
     * answer determines what to fetch or ask next.
     *
     * @param  mixed  $state  Text, a JSON object or array, or `null` to evaluate.
     * @param  array<string, QuestionInterface>  $questions  Non-empty questions keyed by the answer names.
     * @param  string|null  $model  Model override; omitted values use the configured default.
     * @param  array{headers?: array<string, string>, timeout?: float}  $options  Per-call overrides.
     *
     * @throws TypeSafeException When no questions are given or a value is not a question.
     */
    public function systemOne(
        mixed $state,
        array $questions,
        ?string $model = null,
        array $options = [],
    ): SystemOneResult {
        $response = $this->transporter->request('POST', '/v1/systemone', [
            'state' => $state,
            'model' => $model ?? $this->config->defaultModel,
            'questions' => $this->wireQuestions($questions),
        ], $options);

        $data = $response->data;

        if (! is_array($data) || ! is_array($data['answers'] ?? null)) {
            throw new TypeSafeException(
                'Unexpected response shape from POST /v1/systemone; expected { answers: {...} }.'
            );
        }

        return SystemOneResult::fromArray($data);
    }

    /**
     * Validate and encode the questions map.
     *
     * @param  array<string, QuestionInterface>  $questions
     * @return array<string, array<string, mixed>>
     *
     * @throws TypeSafeException When the map is empty or holds a non-question value.
     */
    private function wireQuestions(array $questions): array
    {
        if ($questions === []) {
            throw new TypeSafeException('At least one question is required.');
        }

        $wire = [];

        foreach ($questions as $name => $question) {
            $name = (string) $name;

            if (! $question instanceof QuestionInterface) {
                throw new TypeSafeException(sprintf(
                    'Question "%s" must implement %s, got %s.',
                    $name,
                    QuestionInterface::class,
                    get_debug_type($question),
                ));
            }

            $wire[$name] = $question->toArray();
        }

        return $wire;
    }

    /**
     * Keep credentials out of dumps, logs, and JSON encodings.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseURL' => $this->config->baseURL,
            'defaultModel' => $this->config->defaultModel,
            'timeout' => $this->config->timeout,
            'logLevel' => $this->config->logLevel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
