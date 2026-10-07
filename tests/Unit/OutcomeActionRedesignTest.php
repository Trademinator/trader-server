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
    ['buy', 'neutral', 'hodl'],
    ['buy', 'bull', 'buy'],
    ['buy', 'super_bull', 'buy'],
]);


it('uses Action-only degraded mode to preserve exits without allowing a new BUY', function (string $action, string $expected, float $confidence) {
    $a = ['action' => $action, 'reason' => 'supported', 'confidence' => 0.82, 'neighbors' => 9, 'effective_neighbors' => 7.5, 'similarity' => 0.91];
    $o = ['outcome' => 'neutral', 'reason' => 'no_eligible_k', 'confidence' => 0.0, 'neighbors' => 0, 'effective_neighbors' => 0.0, 'similarity' => 0.0];
    $d = SignalDecisionMatrix::resolve($a, $o);
    expect($d['action'])->toBe($expected)->and($d['reason'])->toBe('degraded_action_only')->and($d['confidence'])->toBe($confidence);
})->with([['sell','sell',0.82],['hodl','hodl',0.82],['buy','hodl',0.0]]);

it('returns HOLD when only Outcome KNN is supported', function () {
    $d = SignalDecisionMatrix::resolve(
        ['action'=>'hodl','reason'=>'no_eligible_k','confidence'=>0.0,'neighbors'=>0,'effective_neighbors'=>0.0,'similarity'=>0.0],
        ['outcome'=>'super_bull','reason'=>'supported','confidence'=>0.88,'neighbors'=>9,'effective_neighbors'=>8.0,'similarity'=>0.94],
    );
    expect($d)->toMatchArray(['action'=>'hodl','reason'=>'degraded_outcome_only','mode'=>'degraded_outcome_only','confidence'=>0.0]);
});
