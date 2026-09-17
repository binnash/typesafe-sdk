<?php

declare(strict_types=1);

use Binnash\Typesafe\DTO\ChoiceResponse;
use Binnash\Typesafe\DTO\NoulResponse;
use Binnash\Typesafe\DTO\ScoreResponse;
use Binnash\Typesafe\DTO\Usage;

describe('Usage', function () {
    it('reads token counts and totals them', function () {
        $usage = Usage::fromArray(['input_tokens' => 312, 'output_tokens' => 48]);

        expect($usage->inputTokens)->toBe(312)
            ->and($usage->outputTokens)->toBe(48)
            ->and($usage->totalTokens())->toBe(360)
            ->and(json_encode($usage))->toBe('{"input_tokens":312,"output_tokens":48}');
    });

    it('defaults to zero tokens', function () {
        expect(Usage::fromArray([])->totalTokens())->toBe(0);
    });
});

describe('NoulResponse', function () {
    it('reports the probability of yes', function () {
        $answer = NoulResponse::fromArray(['type' => 'noul', 'noul' => 0.92]);

        expect($answer->noul)->toBe(0.92)
            ->and($answer->type())->toBe('noul')
            ->and($answer->isYes())->toBeTrue()
            ->and($answer->isYes(0.95))->toBeFalse()
            ->and(json_encode($answer))->toBe('{"type":"noul","noul":0.92}');
    });

    it('treats a coin-flip answer as unclear', function () {
        $answer = NoulResponse::fromArray(['noul' => 0.5]);

        expect($answer->isYes())->toBeTrue()
            ->and($answer->isYes(0.51))->toBeFalse();
    });
});

describe('ChoiceResponse', function () {
    it('reports the winner, confidence, and distribution', function () {
        $answer = ChoiceResponse::fromArray([
            'type' => 'choice',
            'choice' => 'technical',
            'confidence' => 0.82,
            'probabilities' => ['billing' => 0.08, 'technical' => 0.85, 'sales' => 0.07],
        ]);

        expect($answer->choice)->toBe('technical')
            ->and($answer->type())->toBe('choice')
            ->and($answer->confidence)->toBe(0.82)
            ->and($answer->probabilities)->toBe(['billing' => 0.08, 'technical' => 0.85, 'sales' => 0.07])
            ->and($answer->probabilityOf('technical'))->toBe(0.85)
            ->and($answer->probabilityOf('missing', 0.5))->toBe(0.5)
            ->and(json_encode($answer))->toBe(json_encode([
                'type' => 'choice',
                'choice' => 'technical',
                'confidence' => 0.82,
                'probabilities' => ['billing' => 0.08, 'technical' => 0.85, 'sales' => 0.07],
            ]));
    });

    it('tolerates a missing distribution', function () {
        $answer = ChoiceResponse::fromArray(['choice' => 'a']);

        expect($answer->probabilities)->toBe([])
            ->and($answer->confidence)->toBe(0.0);
    });
});

describe('ScoreResponse', function () {
    it('reports the weighted score, legend, and distribution', function () {
        $answer = ScoreResponse::fromArray([
            'type' => 'score',
            'score' => 1.6,
            'confidence' => 0.78,
            'legend' => ['0' => 'Calm', '1' => 'Frustrated', '2' => 'Very angry'],
            'probabilities' => ['0' => 0.05, '1' => 0.3, '2' => 0.65],
        ]);

        expect($answer->score)->toBe(1.6)
            ->and($answer->type())->toBe('score')
            ->and($answer->confidence)->toBe(0.78)
            ->and($answer->legend)->toBe(['Calm', 'Frustrated', 'Very angry'])
            ->and($answer->levelDescription(2))->toBe('Very angry')
            ->and($answer->levelDescription(9))->toBeNull()
            ->and($answer->probabilityOf(0))->toBe(0.05)
            ->and($answer->probabilityOf(9, 0.5))->toBe(0.5);
    });

    it('tolerates a missing rubric', function () {
        $answer = ScoreResponse::fromArray(['score' => 1]);

        expect($answer->legend)->toBe([])
            ->and($answer->probabilities)->toBe([]);
    });
});
