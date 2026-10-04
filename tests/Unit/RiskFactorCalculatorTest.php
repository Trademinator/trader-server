<?php

use App\Domain\Client\RiskFactorCalculator;

it('returns exactly one quarter only as the no-questionnaire default', function () {
    $result = (new RiskFactorCalculator)->calculate(null);

    expect($result)->toBe([
        'risk_factor' => '0.2500000000000000',
        'algorithm_version' => 'questionnaire-risk-v1',
        'source' => 'default',
        'restraint_score' => null,
        'components' => [],
        'unknown_fields' => [],
    ]);
});

it('preserves the one-quarter baseline for a saved all-unknown questionnaire', function () {
    $calculator = new RiskFactorCalculator;
    foreach ([[], array_fill_keys(['loss_impact', 'risk', 'money_needed', 'experience'], 'unsure')] as $answers) {
        $result = $calculator->calculate($answers);
        expect($result['risk_factor'])->toBe('0.2500000000000000')
            ->and($result['source'])->toBe('questionnaire')
            ->and($result['restraint_score'])->toBe('0.3010299956639812')
            ->and($result['unknown_fields'])->toBe(['loss_impact', 'risk', 'money_needed', 'experience']);
    }
});

it('matches independent high-precision references for every profile combination', function () {
    // Generated with Python Decimal at precision 80 using 100 ** (-S), not the
    // PHP implementation's range-reduced exponential. Includes unknown values.
    $cases = json_decode(file_get_contents(__DIR__.'/../Fixtures/client-risk-factor-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $calculator = new RiskFactorCalculator;
    expect($cases)->toHaveCount(192);

    foreach ($cases as $case) {
        $result = $calculator->calculate($case['answers']);
        expect($result['risk_factor'])->toBe($case['risk_factor'])
            ->and($result['restraint_score'])->toBe($case['restraint_score'])
            ->and(bccomp($result['risk_factor'], '0.01', 16))->toBeGreaterThanOrEqual(0)
            ->and(bccomp($result['risk_factor'], '1', 16))->toBeLessThanOrEqual(0);
    }
});

it('reaches both endpoints without selecting a cap or clipping the result', function () {
    $calculator = new RiskFactorCalculator;
    $lowest = $calculator->calculate(['loss_impact' => 'yes', 'risk' => 'low', 'money_needed' => 'soon', 'experience' => 'new']);
    $highest = $calculator->calculate(['loss_impact' => 'no', 'risk' => 'high', 'money_needed' => 'later', 'experience' => 'experienced']);

    expect($lowest['restraint_score'])->toBe('1.0000000000000000')
        ->and($lowest['risk_factor'])->toBe('0.0100000000000000')
        ->and($highest['restraint_score'])->toBe('0.0000000000000000')
        ->and($highest['risk_factor'])->toBe('1.0000000000000000');
});

it('reproduces the approved mixed-profile examples', function () {
    $calculator = new RiskFactorCalculator;
    $answers = ['loss_impact' => 'no', 'risk' => 'medium', 'money_needed' => 'months', 'experience' => 'some'];
    $first = $calculator->calculate($answers);
    $answers['loss_impact'] = 'yes';
    $second = $calculator->calculate($answers);

    expect($first['risk_factor'])->toBe('0.2511886431509580')
        ->and($first['restraint_score'])->toBe('0.3000000000000000')
        ->and($second['risk_factor'])->toBe('0.0398107170553497')
        ->and($second['restraint_score'])->toBe('0.7000000000000000');
});

it('decreases R for more restrictive known answers in every other-answer context', function () {
    $cases = json_decode(file_get_contents(__DIR__.'/../Fixtures/client-risk-factor-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $calculator = new RiskFactorCalculator;
    $orders = [
        'loss_impact' => ['no', 'yes'],
        'risk' => ['high', 'medium', 'low'],
        'money_needed' => ['later', 'months', 'soon'],
        'experience' => ['experienced', 'some', 'new'],
    ];

    foreach ($cases as $case) {
        foreach ($orders as $field => $answers) {
            $previous = null;
            foreach ($answers as $answer) {
                $value = $calculator->calculate(array_replace($case['answers'], [$field => $answer]))['risk_factor'];
                if ($previous !== null) {
                    expect(bccomp($value, $previous, 16))->toBe(-1);
                }
                $previous = $value;
            }
        }
    }
});

it('uses baseline substitution only for unknown components and reports why', function () {
    $calculator = new RiskFactorCalculator;
    $result = $calculator->calculate(['loss_impact' => 'yes', 'risk' => 'unsure', 'money_needed' => ['bad'], 'experience' => false]);

    expect($result['source'])->toBe('questionnaire')
        ->and(bccomp($result['risk_factor'], '0.25', 16))->toBe(-1)
        ->and($result['components']['loss_impact']['status'])->toBe('answered')
        ->and($result['components']['risk']['status'])->toBe('unsure')
        ->and($result['components']['money_needed']['status'])->toBe('unrecognized')
        ->and($result['components']['money_needed']['answer'])->toBeNull()
        ->and($result['components']['experience']['status'])->toBe('unrecognized')
        ->and($result['unknown_fields'])->toBe(['risk', 'money_needed', 'experience']);
});

it('does not replace missing answers with the form defaults', function () {
    $result = (new RiskFactorCalculator)->calculate(['risk' => 'medium']);
    expect($result['components']['experience']['answer'])->toBeNull()
        ->and($result['components']['experience']['status'])->toBe('missing')
        ->and($result['components']['experience']['score'])->toBe('0.3010299956639812');
});

it('ignores unrelated fields and never echoes them in its breakdown', function () {
    $calculator = new RiskFactorCalculator;
    $answers = ['loss_impact' => 'no', 'risk' => 'medium', 'money_needed' => 'months', 'experience' => 'some'];
    $result = $calculator->calculate($answers + [
        'risk_factor' => '1', 'user_id' => 'someone-else', 'country' => 'CA',
        'holdings' => [['asset' => 'BTC', 'band' => 'over_10000']],
        'goal' => 'grow', 'monitoring' => 'frequent', 'allocation' => 'over_10000',
    ]);
    expect($result)->toBe($calculator->calculate($answers))
        ->and(array_keys($result['components']))->toBe(['loss_impact', 'risk', 'money_needed', 'experience']);
});

it('is independent of the global BCMath scale and does not change it', function () {
    $original = bcscale();
    try {
        foreach ([0, 2, 8, 50] as $scale) {
            bcscale($scale);
            $result = (new RiskFactorCalculator)->calculate(['loss_impact' => 'no', 'risk' => 'medium', 'money_needed' => 'months', 'experience' => 'some']);
            expect($result['risk_factor'])->toBe('0.2511886431509580')
                ->and(bcscale())->toBe($scale);
        }
    } finally {
        bcscale($original);
    }
});
