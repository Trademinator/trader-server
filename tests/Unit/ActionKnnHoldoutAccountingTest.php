<?php

use App\Domain\Intelligence\KnnTuner;
use App\Domain\Intelligence\SignalSemantics;
use App\Domain\Intelligence\WeightedKnn;

function accountedActionRow(?string $label, int $index): array
{
    return ['decision_at_ms' => $index * 1000, 'label_available_at_ms' => ($index + 3) * 1000,
        'label' => $label, 'semantic_bottom' => $label === 'buy', 'semantic_top' => $label === 'sell'];
}

function accountedPrediction(string $action, string $reason = 'supported'): array
{
    return ['action' => $action, 'reason' => $reason,
        'confidence' => $reason === 'supported' ? 0.8 : 0.0];
}

function actionAccountingSettings(): array
{
    return ['min_validation_rows' => 1, 'min_directional_predictions' => 1,
        'min_semantic_precision' => 0.0, 'min_coverage' => 0.0, 'max_contradiction_rate' => 1.0];
}

it('separates supported HOLDs from abstentions and classifies historical errors', function () {
    $labels = ['buy', 'sell', 'hodl', 'hodl', 'hodl', 'buy', 'sell'];
    $rows = array_map(fn (string $label, int $i): array => accountedActionRow($label, $i + 1),
        $labels, array_keys($labels));
    $predictions = [
        accountedPrediction('sell'), // SELL on BUY
        accountedPrediction('buy'), // BUY on SELL
        accountedPrediction('buy'), // BUY on HOLD
        accountedPrediction('sell'), // SELL on HOLD
        accountedPrediction('hodl'), // Supported, correct HOLD
        accountedPrediction('hodl', 'no_similar_history'), // Abstain, NOT a HOLD vote
        accountedPrediction('hodl'), // Supported HOLD on SELL
    ];
    $report = (new KnnTuner(new WeightedKnn))->evaluatePredictions($rows, $predictions, actionAccountingSettings());

    expect($report['evaluation_basis'])->toBe('finalized_historical_action_labels')
        ->and($report['evaluated'])->toBe(7)
        ->and($report['supported'])->toBe(6)
        ->and($report['abstained'])->toBe(1)
        ->and($report['supported_holds'])->toBe(2)
        ->and($report['correct_holds'])->toBe(1)
        ->and($report['abstentions_by_label'])->toBe(['buy' => 1, 'hodl' => 0, 'sell' => 0])
        ->and($report['confusion']['buy']['hodl'])->toBe(0)
        ->and($report['confusion']['sell']['hodl'])->toBe(1)
        ->and($report['confusion']['hodl']['hodl'])->toBe(1)
        ->and($report['classification_errors'])->toBe([
            'buy_on_sell' => 1, 'sell_on_buy' => 1, 'buy_on_hold' => 1,
            'sell_on_hold' => 1, 'hold_on_buy' => 0, 'hold_on_sell' => 1,
        ])
        ->and($report['opposite_action_predictions'])->toBe(2)
        ->and($report['directional_predictions_on_hold'])->toBe(2)
        ->and($report['contradiction_rate'])->toBe(0.5);
});

it('does not treat an abstention-only holdout as evidence of correct HOLDs', function () {
    $rows = [accountedActionRow('hodl', 1), accountedActionRow('hodl', 2)];
    $predictions = [accountedPrediction('hodl', 'weak_consensus'), accountedPrediction('hodl', 'no_similar_history')];
    $report = (new KnnTuner(new WeightedKnn))->evaluatePredictions($rows, $predictions, actionAccountingSettings());

    expect($report['abstained'])->toBe(2)
        ->and($report['supported'])->toBe(0)
        ->and($report['supported_holds'])->toBe(0)
        ->and($report['correct_holds'])->toBe(0)
        ->and($report['confusion']['hodl']['hodl'])->toBe(0)
        ->and($report['eligible'])->toBeFalse()
        ->and($report['failed_gates'])->toContain('directional_predictions');
});

it('rejects unlabelled or immature provisional candles from historical holdout', function () {
    $tuner = new KnnTuner(new WeightedKnn);
    expect(fn () => $tuner->evaluatePredictions([accountedActionRow(null, 1)],
        [accountedPrediction('buy')], actionAccountingSettings()))
        ->toThrow(InvalidArgumentException::class, 'finalized historical');
    $notFinal = accountedActionRow('buy', 1);
    $notFinal['label_available_at_ms'] = $notFinal['decision_at_ms'];
    expect(fn () => $tuner->evaluatePredictions([$notFinal],
        [accountedPrediction('buy')], actionAccountingSettings()))
        ->toThrow(InvalidArgumentException::class, 'finalized historical');
});

it('maps Server abstentions to Client HOLD while retaining the reason', function () {
    expect(SignalSemantics::clientAction('hodl', 'supported'))->toBe('hold')
        ->and(SignalSemantics::clientAction('buy', 'weak_consensus'))->toBe('hold')
        ->and(SignalSemantics::clientAction('sell', 'no_similar_history'))->toBe('hold')
        ->and(SignalSemantics::clientAction('sell', 'degraded_action_only'))->toBe('sell')
        ->and(SignalSemantics::clientAction('buy', 'degraded_action_only'))->toBe('hold')
        ->and(SignalSemantics::evidenceStatus('weak_consensus'))->toBe('abstaining')
        ->and(SignalSemantics::evidenceStatus('supported'))->toBe('supported');
});
