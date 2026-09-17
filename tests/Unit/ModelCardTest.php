<?php

declare(strict_types=1);

use Binnash\Typesafe\DTO\ModelCard;

it('reads the documented fields', function () {
    $card = ModelCard::fromArray([
        'name' => 'jev-latest',
        'description' => 'The most recent stable release.',
        'release_date' => '2026-01-01',
    ]);

    expect($card->name)->toBe('jev-latest')
        ->and($card->description)->toBe('The most recent stable release.')
        ->and($card->releaseDate)->toBe('2026-01-01');
});

it('preserves undocumented fields through raw access', function () {
    $wire = [
        'name' => 'jev-1.13.0',
        'description' => 'Pinned release.',
        'release_date' => '2025-11-01',
        'tags' => ['internal'],
    ];

    $card = ModelCard::fromArray($wire);

    expect($card->raw)->toBe($wire)
        ->and($card->toArray())->toBe($wire)
        ->and(json_encode($card))->toBe(json_encode($wire));
});

it('tolerates missing fields', function () {
    $card = ModelCard::fromArray(['name' => 'only-name']);

    expect($card->name)->toBe('only-name')
        ->and($card->description)->toBe('')
        ->and($card->releaseDate)->toBe('');
});
