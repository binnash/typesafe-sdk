<?php

declare(strict_types=1);

use Binnash\Typesafe\Exceptions\TypeSafeException;
use Binnash\Typesafe\Questions\ChoiceQuestion;
use Binnash\Typesafe\Questions\NoulQuestion;
use Binnash\Typesafe\Questions\QuestionInterface;
use Binnash\Typesafe\Questions\ScoreQuestion;
use Binnash\Typesafe\Support\Question;

describe('noul', function () {
    it('builds a yes/no question', function () {
        $question = Question::noul('Does this convey urgency?');

        expect($question)->toBeInstanceOf(NoulQuestion::class)
            ->and($question)->toBeInstanceOf(QuestionInterface::class)
            ->and($question->type())->toBe('noul')
            ->and($question->instructions())->toBe('Does this convey urgency?')
            ->and($question->criteria())->toBeNull()
            ->and($question->toArray())->toBe([
                'type' => 'noul',
                'instructions' => 'Does this convey urgency?',
                'criteria' => null,
            ]);
    });

    it('defaults the instructions to null', function () {
        expect(Question::noul()->toArray())->toBe([
            'type' => 'noul',
            'instructions' => null,
            'criteria' => null,
        ]);
    });

    it('accepts one, both, or neither outcome description', function () {
        expect(Question::noul('q', ['true' => 'yes means this'])->criteria())->toBe(['true' => 'yes means this'])
            ->and(Question::noul('q', ['false' => 'no means this'])->criteria())->toBe(['false' => 'no means this'])
            ->and(Question::noul('q', ['true' => 'a', 'false' => 'b'])->criteria())->toBe(['true' => 'a', 'false' => 'b']);
    });

    it('rejects criteria keys other than true and false', function () {
        expect(fn () => Question::noul('q', ['yes' => 'a']))
            ->toThrow(TypeSafeException::class, 'Noul criteria keys must be "true" and/or "false"; got "yes".');
    });
});

describe('choice', function () {
    it('builds a question from a map of options', function () {
        $question = Question::choice('Which team should handle this?', [
            'billing' => 'Payments, invoicing, refunds',
            'technical' => null,
        ]);

        expect($question)->toBeInstanceOf(ChoiceQuestion::class)
            ->and($question->type())->toBe('choice')
            ->and($question->criteria())->toBe(['billing' => 'Payments, invoicing, refunds', 'technical' => null])
            ->and($question->toArray())->toBe([
                'type' => 'choice',
                'instructions' => 'Which team should handle this?',
                'criteria' => ['billing' => 'Payments, invoicing, refunds', 'technical' => null],
            ]);
    });

    it('rejects the label-list shorthand', function () {
        expect(fn () => Question::choice('q', ['a', 'b']))
            ->toThrow(TypeSafeException::class, 'Choice criteria must be a map of labels to descriptions, not a list.');
    });

    it('rejects empty criteria', function () {
        expect(fn () => Question::choice('q', []))
            ->toThrow(TypeSafeException::class, 'Choice criteria must contain at least one option.');
    });
});

describe('score', function () {
    it('builds a question from an ordered rubric', function () {
        $question = Question::score('How urgent is this?', ['can wait', 'this week', 'today']);

        expect($question)->toBeInstanceOf(ScoreQuestion::class)
            ->and($question->type())->toBe('score')
            ->and($question->criteria())->toBe(['can wait', 'this week', 'today'])
            ->and($question->toArray())->toBe([
                'type' => 'score',
                'instructions' => 'How urgent is this?',
                'criteria' => ['can wait', 'this week', 'today'],
            ]);
    });

    it('rejects criteria maps', function (array $criteria) {
        expect(fn () => Question::score('q', $criteria))
            ->toThrow(TypeSafeException::class, 'Score criteria must be a list of descriptions indexed by score from zero, not a map.');
    })->with([
        'string keys' => [['low' => 'bad', 'high' => 'good']],
        'non-sequential keys' => [[0 => 'bad', 2 => 'good']],
    ]);

    it('rejects rubrics with fewer than two levels', function (array $criteria, int $count) {
        expect(fn () => Question::score('q', $criteria))
            ->toThrow(TypeSafeException::class, sprintf('Score criteria must contain at least two levels; got %d.', $count));
    })->with([
        'empty' => [[], 0],
        'single level' => [['only'], 1],
    ]);
});

describe('rich criteria', function () {
    it('allows JSON objects and arrays as descriptions', function () {
        $rich = ['summary' => 'warm', 'examples' => ['hi!', 'welcome']];

        expect(Question::choice('q', ['friendly' => $rich, 'hostile' => null])->criteria()['friendly'])->toBe($rich)
            ->and(Question::score('q', [$rich, 'meh'])->criteria()[0])->toBe($rich)
            ->and(Question::noul('q', ['true' => $rich])->criteria()['true'])->toBe($rich);
    });

    it('preserves null instructions and null criteria values on the wire', function () {
        $questions = [
            'noul' => Question::noul(null, ['true' => null, 'false' => null]),
            'choice' => Question::choice(null, ['yes' => null, 'no' => null]),
            'score' => Question::score(null, [null, 'high']),
        ];

        expect(json_encode($questions))->toBe(json_encode([
            'noul' => ['type' => 'noul', 'instructions' => null, 'criteria' => ['true' => null, 'false' => null]],
            'choice' => ['type' => 'choice', 'instructions' => null, 'criteria' => ['yes' => null, 'no' => null]],
            'score' => ['type' => 'score', 'instructions' => null, 'criteria' => [null, 'high']],
        ]));
    });

    it('accepts arrays and objects as instructions', function () {
        $instructions = ['question' => 'Is this billing?', 'examples' => ['charged twice']];

        expect(Question::noul($instructions)->instructions())->toBe($instructions)
            ->and(json_encode(Question::noul($instructions)))->toBe(json_encode([
                'type' => 'noul',
                'instructions' => $instructions,
                'criteria' => null,
            ]));
    });
});

describe('global helpers', function () {
    it('builds every primitive', function () {
        expect(noul('q'))->toBeInstanceOf(NoulQuestion::class)
            ->and(choice('q', ['a' => null]))->toBeInstanceOf(ChoiceQuestion::class)
            ->and(score('q', ['low', 'high']))->toBeInstanceOf(ScoreQuestion::class);
    });

    it('produces the same payload as the namespaced factories', function () {
        expect(json_encode(choice('c', ['a' => 'A'])))->toBe(json_encode(Question::choice('c', ['a' => 'A'])))
            ->and(json_encode(score('s', ['low', 'high'])))->toBe(json_encode(Question::score('s', ['low', 'high'])))
            ->and(json_encode(noul('n')))->toBe(json_encode(Question::noul('n')));
    });
});
