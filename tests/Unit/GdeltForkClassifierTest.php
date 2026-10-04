<?php

use App\Domain\MarketEvents\GdeltForkClassifier;

it('recognizes explicit chain splits as fork evidence without the word fork', function (string $title) {
    $classifier = new GdeltForkClassifier;

    $result = $classifier->classify($title, [], ['BTC']);

    expect($result['event_type'])->toBe('chain_split')
        ->and($result['confidence'])->toBe(0.75)
        ->and($result['matched_symbols'])->toBe(['BTC'])
        ->and($result['evidence']['flags']['chainSplit'])->toBeTrue()
        ->and($result['evidence']['flags']['genericFork'])->toBeFalse()
        ->and($result['evidence']['classification_source'])->toBe('gkg_title')
        ->and($result['context_snippet'])->toBeNull();
})->with([
    'chain split' => ['BTC chain split creates NEWBTC for holders'],
    'blockchain split' => ['BTC blockchain split creates NEWBTC for holders'],
    'uppercase title' => ['BTC CHAIN SPLIT CREATES NEWBTC FOR HOLDERS'],
]);

it('does not double count fork evidence when both fork and split wording appear', function () {
    $classifier = new GdeltForkClassifier;

    $result = $classifier->classify('BTC hard fork causes a chain split', [], ['BTC']);

    expect($result['event_type'])->toBe('chain_split')
        ->and($result['confidence'])->toBe(0.75)
        ->and($result['evidence']['flags']['genericFork'])->toBeTrue()
        ->and($result['evidence']['flags']['chainSplit'])->toBeTrue();
});

it('preserves confidence for titles without explicit chain split evidence', function (string $title, string $symbol, float $confidence) {
    $classifier = new GdeltForkClassifier;

    $result = $classifier->classify($title, [], [$symbol]);

    expect($result['event_type'])->toBe('unknown')
        ->and($result['confidence'])->toBe($confidence)
        ->and($result['matched_symbols'])->toBe([$symbol]);
})->with([
    'ambiguous hard fork' => ['ADA hard fork scheduled for October', 'ADA', 0.60],
    'generic fork' => ['BTC fork planned for October', 'BTC', 0.50],
    'unrelated title' => ['BTC holders discuss market prices', 'BTC', 0.25],
]);
