<?php

use App\Domain\Intelligence\TrainingSourceFusion;

function supportedPrediction(string $key, string $winner, array $labels, float $confidence = 0.8): array
{
    $votes = array_fill_keys($labels, 0.0);
    $votes[$winner] = $confidence;
    $remaining = (1 - $confidence) / max(1, count($labels) - 1);
    foreach ($votes as $label => &$vote) {
        if ($label !== $winner) {
            $vote = $remaining;
        }
    }
    unset($vote);

    return [
        $key => $winner,
        'confidence' => $confidence,
        'reason' => 'supported',
        'neighbors' => 10,
        'effective_neighbors' => 8.0,
        'similarity' => 1.0,
        'votes' => $votes,
    ];
}

it('uses dynamic human weight when both training sources support Action KNN', function () {
    $labels = ['buy', 'hodl', 'sell'];
    $fusion = new TrainingSourceFusion;
    $result = $fusion->combine(
        supportedPrediction('action', 'buy', $labels, 0.7),
        supportedPrediction('action', 'sell', $labels, 0.7),
        $labels,
        'action',
        50,
    );

    $human = 0.60 * sqrt(50 / 750);
    expect($result['sources']['configured_weights']['human'])->toEqualWithDelta($human, 1e-12)
        ->and($result['sources']['effective_weights']['human'])->toEqualWithDelta($human, 1e-12)
        ->and($result['sources']['effective_weights']['algorithmic'])->toEqualWithDelta(1 - $human, 1e-12);
});

it('gives the available source all effective weight when the other source abstains', function () {
    $labels = ['buy', 'hodl', 'sell'];
    $fusion = new TrainingSourceFusion;
    $abstain = [
        'action' => 'hodl', 'confidence' => 0.0, 'reason' => 'no_model',
        'neighbors' => 0, 'effective_neighbors' => 0.0, 'similarity' => 0.0,
        'votes' => array_fill_keys($labels, 0.0),
    ];

    $result = $fusion->combine(
        supportedPrediction('action', 'buy', $labels),
        $abstain,
        $labels,
        'action',
        750,
    );

    expect($result['sources']['effective_weights'])->toBe(['algorithmic' => 1.0, 'human' => 0.0]);
});
