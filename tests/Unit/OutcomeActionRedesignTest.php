<?php

use App\Domain\Intelligence\ActionAutoLabeler;
use App\Domain\Intelligence\HumanTrainingWeight;
use App\Domain\Intelligence\SignalDecisionMatrix;
use App\Domain\Research\SemanticLabels;

it('derives Outcome horizon from the frequency-weighted Action pivot distance', function () {
    $rows = array_fill(0, 31, []);
    foreach ([0 => 'buy', 4 => 'sell', 9 => 'buy', 13 => 'sell', 19 => 'buy', 23 => 'sell', 30 => 'buy'] as $index => $action) {
        $rows[$index]['action'] = $action;
    }

    // Distances: 4,5,4,6,4,7 => (4*3 + 5 + 6 + 7) / 6 = 5
    expect((new ActionAutoLabeler)->horizon($rows))->toBe(5);
});

it('uses the agreed hard Outcome boundaries', function (float $m, string $expected) {
    expect(SemanticLabels::classify($m))->toBe($expected);
})->with([
    [-1.00, 'super_bear'],
    [-0.61, 'super_bear'],
    [-0.60, 'bear'],
    [-0.21, 'bear'],
    [-0.20, 'neutral'],
    [0.00, 'neutral'],
    [0.20, 'neutral'],
    [0.21, 'bull'],
    [0.60, 'bull'],
    [0.61, 'super_bull'],
    [1.00, 'super_bull'],
]);

it('keeps Human Training influence gradual and capped', function () {
    expect(HumanTrainingWeight::human(0))->toBe(0.0)
        ->and(round(HumanTrainingWeight::human(50), 3))->toBe(0.155)
        ->and(round(HumanTrainingWeight::human(100), 3))->toBe(0.219)
        ->and(round(HumanTrainingWeight::human(300), 3))->toBe(0.379)
        ->and(round(HumanTrainingWeight::human(500), 3))->toBe(0.490)
        ->and(HumanTrainingWeight::human(750))->toBe(0.60)
        ->and(HumanTrainingWeight::human(5000))->toBe(0.60);
});

it('applies all fifteen Outcome and Action combinations', function (string $action, string $outcome, string $expected) {
    expect(SignalDecisionMatrix::decide($action, $outcome))->toBe($expected);
})->with([
    ['sell', 'super_bear', 'sell'],
    ['sell', 'bear', 'sell'],
    ['sell', 'neutral', 'sell'],
    ['sell', 'bull', 'hodl'],
    ['sell', 'super_bull', 'hodl'],
    ['hodl', 'super_bear', 'hodl'],
    ['hodl', 'bear', 'hodl'],
    ['hodl', 'neutral', 'hodl'],
    ['hodl', 'bull', 'hodl'],
    ['hodl', 'super_bull', 'hodl'],
    ['buy', 'super_bear', 'hodl'],
    ['buy', 'bear', 'hodl'],
    ['buy', 'neutral', 'buy'],
    ['buy', 'bull', 'buy'],
    ['buy', 'super_bull', 'buy'],
]);


it('supports Action BUY with a neutral Outcome and keeps the lower confidence', function () {
    $a = ['action' => 'buy', 'reason' => 'supported', 'confidence' => 0.88,
        'neighbors' => 10, 'effective_neighbors' => 8.5, 'similarity' => 0.90];
    $o = ['outcome' => 'neutral', 'reason' => 'supported', 'confidence' => 0.70,
        'neighbors' => 9, 'effective_neighbors' => 7.5, 'similarity' => 0.87];
    expect(SignalDecisionMatrix::resolve($a, $o))->toMatchArray([
        'action' => 'buy', 'reason' => 'supported', 'mode' => 'full', 'confidence' => 0.70,
    ]);
});

it('preserves supported Action predictions when Outcome cannot decide', function (string $action, string $expected) {
    $a = ['action' => $action, 'reason' => 'supported', 'confidence' => 0.82, 'neighbors' => 9, 'effective_neighbors' => 7.5, 'similarity' => 0.91];
    $o = ['outcome' => 'neutral', 'reason' => 'no_eligible_k', 'confidence' => 0.0, 'neighbors' => 0, 'effective_neighbors' => 0.0, 'similarity' => 0.0];
    $d = SignalDecisionMatrix::resolve($a, $o);
    expect($d)->toMatchArray(['action' => $expected, 'reason' => 'degraded_action_only',
        'confidence' => 0.82, 'neighbors' => 9, 'effective_neighbors' => 7.5, 'similarity' => 0.91]);
})->with([['sell','sell'],['hodl','hodl'],['buy','buy']]);

it('still refuses an unsupported Action BUY even if Outcome is unavailable', function () {
    $a = ['action' => 'buy', 'reason' => 'weak_consensus', 'confidence' => 0.90];
    $o = ['outcome' => 'neutral', 'reason' => 'holdout_failed'];
    expect(SignalDecisionMatrix::resolve($a, $o))->toMatchArray([
        'action' => 'hodl', 'reason' => 'knn_abstention', 'confidence' => 0.0,
    ]);
});

it('returns HOLD when only Outcome KNN is supported', function () {
    $d = SignalDecisionMatrix::resolve(
        ['action'=>'hodl','reason'=>'no_eligible_k','confidence'=>0.0,'neighbors'=>0,'effective_neighbors'=>0.0,'similarity'=>0.0],
        ['outcome'=>'super_bull','reason'=>'supported','confidence'=>0.88,'neighbors'=>9,'effective_neighbors'=>8.0,'similarity'=>0.94],
    );
    expect($d)->toMatchArray(['action'=>'hodl','reason'=>'degraded_outcome_only','mode'=>'degraded_outcome_only','confidence'=>0.0]);
});
