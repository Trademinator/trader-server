<?php

use App\Domain\Intelligence\AutomaticPatternAblation;

function ablationCandidate(int $k, float $precision, bool $eligible = true): array
{
    return [
        'k' => $k,
        'evaluated' => 100,
        'directional' => 20,
        'semantic_precision' => $precision,
        'contradiction_rate' => 0.0,
        'coverage' => 0.2,
        'mean_confidence' => 0.7,
        'stability' => 0.9,
        'eligible' => $eligible,
        'failed_gates' => $eligible ? [] : ['semantic_precision'],
    ];
}

it('compares pattern stacking at the same K and keeps final holdout outside the decision', function () {
    $selector = new AutomaticPatternAblation;
    $keys = ['pattern.evening_star.probability', 'pattern.evening_star.present'];

    $better = $selector->chooseAtK(ablationCandidate(9, 0.60), ablationCandidate(9, 0.66), $keys, 1000);
    $worse = $selector->chooseAtK(ablationCandidate(9, 0.60), ablationCandidate(9, 0.58), $keys, 1000);

    expect($better['selected'])->toBe('technical_plus_patterns');
    expect($better['reason'])->toBe('patterns_improve_automatic_selection');
    expect($better['selection_basis'])->toBe('same_k_same_walk_forward_rows_final_holdout_untouched');
    expect($better['comparison_k'])->toBe(9);
    expect($worse['selected'])->toBe('technical_only');
    expect($worse['reason'])->toBe('technical_equal_or_better_on_automatic_selection');
});

it('uses the best observed technical K when no candidate is yet eligible', function () {
    $selector = new AutomaticPatternAblation;
    $selection = ['k' => null, 'candidates' => [
        ablationCandidate(5, 0.42, false),
        ablationCandidate(9, 0.46, false),
        ablationCandidate(17, 0.44, false),
    ]];

    expect($selector->comparisonCandidate($selection)['k'])->toBe(9);
});

it('requires stacked features to pass the automatic gates before they can rescue the model', function () {
    $selector = new AutomaticPatternAblation;
    $keys = ['pattern.evening_star.probability', 'pattern.evening_star.present'];

    $rescued = $selector->chooseAtK(ablationCandidate(9, 0.50, false), ablationCandidate(9, 0.60), $keys, 1000);
    $unproven = $selector->chooseAtK(ablationCandidate(9, 0.50, false), ablationCandidate(9, 0.54, false), $keys, 1000);

    expect($rescued['selected'])->toBe('technical_plus_patterns');
    expect($rescued['reason'])->toBe('patterns_create_eligible_automatic_model');
    expect($unproven['selected'])->toBe('technical_only');
    expect($unproven['reason'])->toBe('patterns_not_validated_for_automatic_target');
});
