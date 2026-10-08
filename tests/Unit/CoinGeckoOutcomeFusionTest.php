<?php

uses(Tests\TestCase::class);

use App\Domain\Intelligence\CoinGeckoOutcomeFusion;
use App\Domain\Intelligence\CoinGeckoInsight;

beforeEach(function (): void {
    config(['intelligence.coingecko_insight.enabled' => true,
        'intelligence.coingecko_insight.influence_enabled' => false,
        'intelligence.coingecko_insight.max_weight' => 0.15]);
});

function coreOutcomeForCoinGecko(): array
{
    return ['reason' => 'supported', 'outcome' => 'bull',
        'confidence' => 0.65, 'similarity' => 1.0,
        'votes' => ['super_bear' => 0.05, 'bear' => 0.10, 'neutral' => 0.10,
            'bull' => 0.65, 'super_bull' => 0.10]];
}

function bearishCoinGeckoInsight(): array
{
    return ['reason' => 'supported', 'outcome' => 'bear',
        'confidence' => 0.80,
        'votes' => ['super_bear' => 0.10, 'bear' => 0.80, 'neutral' => 0.05,
            'bull' => 0.03, 'super_bull' => 0.02]];
}

it('remains a shadow advisor unless influence is explicitly enabled', function (): void {
    $core = coreOutcomeForCoinGecko();
    $result = (new CoinGeckoOutcomeFusion)->combine($core, bearishCoinGeckoInsight(),
        ['version' => CoinGeckoInsight::VERSION, 'status' => 'validated',
            'holdout' => ['f1_improvement' => 0.1]], 0.60);

    expect($result['outcome'])->toBe($core)
        ->and($result['fusion']['weight'])->toBe(0.0)
        ->and($result['fusion']['reason'])->toBe('shadow_mode');
});

it('can veto a weak bullish consensus but cannot supply an unsupported primary outcome', function (): void {
    config(['intelligence.coingecko_insight.influence_enabled' => true]);
    $bundle = ['status' => 'validated', 'holdout' => ['f1_improvement' => 0.10]];
    $fusion = new CoinGeckoOutcomeFusion;
    $veto = $fusion->combine(coreOutcomeForCoinGecko(), bearishCoinGeckoInsight(), $bundle, 0.60);

    expect($veto['fusion']['applied'])->toBeTrue()
        ->and($veto['fusion']['weight'])->toBe(0.15)
        ->and($veto['outcome']['reason'])->toBe('weak_context_consensus')
        ->and($veto['outcome']['outcome'])->toBe('neutral');

    $abstain = coreOutcomeForCoinGecko();
    $abstain['reason'] = 'no_similar_history';
    expect($fusion->combine($abstain, bearishCoinGeckoInsight(), $bundle, 0.6)['outcome'])
        ->toBe($abstain);
});

it('never applies a model that lacks an eligible validation result', function (): void {
    config(['intelligence.coingecko_insight.influence_enabled' => true]);
    $core = coreOutcomeForCoinGecko();
    $result = (new CoinGeckoOutcomeFusion)->combine($core, bearishCoinGeckoInsight(),
        ['status' => 'holdout_failed', 'holdout' => ['f1_improvement' => 0.0]], 0.6);

    expect($result['outcome'])->toBe($core)
        ->and($result['fusion']['applied'])->toBeFalse();
});
