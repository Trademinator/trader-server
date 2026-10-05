<?php

use App\Domain\Intelligence\KnnEnsemble;
use App\Domain\Intelligence\WeightedKnn;

function ensemblePrediction(array $votes, float $similarity = 1.0): array
{
    arsort($votes);

    return ['action' => array_key_first($votes), 'reason' => 'supported', 'confidence' => max($votes) * $similarity,
        'votes' => $votes, 'similarity' => $similarity, 'neighbors' => 9, 'effective_neighbors' => 7.0];
}

function ensembleSettings(array $weights = ['automatic' => 0.4, 'human_candle' => 0.6], float $minimum = 0.6): array
{
    return ['weights' => $weights, 'min_confidence' => $minimum];
}

it('weights independent action distributions and retains both opinions on disagreement', function () {
    $result = (new KnnEnsemble)->combine(
        ensemblePrediction(['buy' => 1.0, 'hodl' => 0.0, 'sell' => 0.0]),
        ensemblePrediction(['buy' => 0.0, 'hodl' => 0.0, 'sell' => 1.0]), ensembleSettings());

    expect($result['action'])->toBe('sell')->and($result['confidence'])->toBe(0.6)
        ->and($result['votes'])->toBe(['sell' => 0.6, 'buy' => 0.4, 'hodl' => 0.0])
        ->and($result['scoring']['components']['automatic']['action'])->toBe('buy')
        ->and($result['scoring']['components']['human_candle']['action'])->toBe('sell')
        ->and($result['effective_neighbors'])->toBe(7.0)
        ->and($result['scoring']['human_trend']['weight'])->toBe(0.0)
        ->and($result['scoring']['social_news']['weight'])->toBe(0.0);
});

it('uses the full distributions rather than only winning actions', function () {
    $result = (new KnnEnsemble)->combine(
        ensemblePrediction(['buy' => 0.8, 'hodl' => 0.2, 'sell' => 0.0]),
        ensemblePrediction(['buy' => 0.1, 'hodl' => 0.2, 'sell' => 0.7]), ensembleSettings());

    expect($result['votes']['buy'])->toEqualWithDelta(0.38, 1e-12)
        ->and($result['votes']['sell'])->toEqualWithDelta(0.42, 1e-12)
        ->and($result['reason'])->toBe('weak_model_consensus')->and($result['confidence'])->toBe(0.0);
});

it('renormalizes supported evidence and does not turn an abstention into HOLD evidence', function (bool $humanAvailable) {
    $supported = ensemblePrediction(['buy' => 0.0, 'hodl' => 1.0, 'sell' => 0.0], 0.9);
    $missing = WeightedKnn::abstain('missing_selected_features');
    $result = (new KnnEnsemble)->combine($humanAvailable ? $missing : $supported,
        $humanAvailable ? $supported : $missing, ensembleSettings());

    expect($result['action'])->toBe('hodl')->and($result['reason'])->toBe('supported')
        ->and($result['confidence'])->toBe(0.9)
        ->and($result['scoring']['effective_weights'])->toBe($humanAvailable
            ? ['automatic' => 0.0, 'human_candle' => 1.0] : ['automatic' => 1.0, 'human_candle' => 0.0]);
})->with([true, false]);

it('preserves real HOLD evidence when both models participate', function () {
    $result = (new KnnEnsemble)->combine(
        ensemblePrediction(['buy' => 1.0, 'hodl' => 0.0, 'sell' => 0.0]),
        ensemblePrediction(['buy' => 0.0, 'hodl' => 1.0, 'sell' => 0.0]), ensembleSettings());
    expect($result['action'])->toBe('hodl')->and($result['reason'])->toBe('supported')
        ->and($result['votes']['hodl'])->toBe(0.6);
});

it('abstains on ties and when neither model supports a decision', function () {
    $service = new KnnEnsemble;
    $tie = $service->combine(ensemblePrediction(['buy' => 1.0, 'hodl' => 0.0, 'sell' => 0.0]),
        ensemblePrediction(['buy' => 0.0, 'hodl' => 0.0, 'sell' => 1.0]), ensembleSettings(['automatic' => 1, 'human_candle' => 1]));
    expect($tie['reason'])->toBe('tied_model_scores')->and($tie['confidence'])->toBe(0.0);
    $none = $service->combine(WeightedKnn::abstain('no_similar_history'), WeightedKnn::abstain('disabled'), ensembleSettings());
    expect($none['reason'])->toBe('no_similar_history')->and(array_sum($none['scoring']['effective_weights']))->toBe(0.0);
});

it('honors zero configured weight even when that model is the only supported model', function () {
    $result = (new KnnEnsemble)->combine(WeightedKnn::abstain('no_similar_history'),
        ensemblePrediction(['buy' => 1.0, 'hodl' => 0.0, 'sell' => 0.0]), ensembleSettings(['automatic' => 1, 'human_candle' => 0]));
    expect($result['reason'])->toBe('no_similar_history')->and($result['confidence'])->toBe(0.0);
});

it('rejects invalid scoring weights', function (array $weights) {
    KnnEnsemble::settings(ensembleSettings($weights));
})->with([
    [['automatic' => 0, 'human_candle' => 0]],
    [['automatic' => -1, 'human_candle' => 2]],
    [['automatic' => INF, 'human_candle' => 1]],
    [['automatic' => 1]],
])->throws(InvalidArgumentException::class);

it('weights actual neighbors by class without inventing missing-class evidence', function () {
    $knn = new WeightedKnn(minEffective: 1, minConfidence: 0.5);
    $neighbors = [['distance' => 0.0, 'label' => 'buy'], ['distance' => 0.0, 'label' => 'hodl']];
    $result = $knn->vote($neighbors, 2, ['buy' => 3, 'hodl' => 1, 'sell' => 50]);
    expect($result['votes']['buy'])->toEqualWithDelta(0.75, 1e-12)
        ->and($result['votes']['sell'])->toBe(0.0)
        ->and($result['effective_neighbors'])->toEqualWithDelta(1.6, 1e-12);
    expect($knn->vote($neighbors, 2, ['buy' => 0, 'hodl' => 0])['reason'])->toBe('no_weighted_evidence');
});
