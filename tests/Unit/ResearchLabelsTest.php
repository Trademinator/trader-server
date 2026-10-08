<?php

use App\Domain\Research\BaselineBacktester;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\LabelDefinition;
use App\Domain\Research\ResearchInput;
use App\Domain\Research\SemanticLabels;

it('defines buy sell and hodl with fees and slippage on both sides', function () {
    $definition = new LabelDefinition(12, 10, 5, 10);
    expect($definition->label(100, 110)['action'])->toBe('buy')
        ->and($definition->label(100, 90)['action'])->toBe('sell')
        ->and($definition->label(100, 100)['action'])->toBe('hodl')
        ->and($definition->label(100, 100.1)['action'])->toBe('hodl');
    $factor = 0.999 ** 2 * 0.9995 / 1.0005;
    expect($definition->label(100, 110)['buy_net_return'])->toBe(1.1 * $factor - 1)
        ->and($definition->label(100, 90)['sell_base_net_return'])->toBe((100 / 90) * $factor - 1);
});

it('keeps semantic returns explicitly cost free', function () {
    $definition = new SemanticLabels(2, 3, 10, 0.2);
    $past = [
        ['high' => 101, 'low' => 99, 'close' => 100],
        ['high' => 102, 'low' => 100, 'close' => 101],
        ['high' => 103, 'low' => 101, 'close' => 102],
    ];
    $future = [
        ['close' => 100, 'open' => 100],
        ['close' => 100.5, 'open' => 100.25],
        ['close' => 101, 'open' => 100.5],
    ];

    $label = $definition->label($past, $future);

    expect($label)->toHaveKeys(['gross_return', 'buy_price_return', 'sell_base_price_return'])
        ->not->toHaveKeys(['buy_net_return', 'sell_base_net_return'])
        ->and($label['buy_price_return'])->toBe($label['gross_return']);
    expect($definition->metadata())
        ->toMatchArray(['version' => SemanticLabels::VERSION, 'cost_model' => 'none', 'fee_bps' => 0, 'slippage_bps' => 0]);
});

it('refuses cost-free semantic knowledge in the fee-aware M3 portfolio backtester', function () {
    $manifest = ['label_definition' => (new SemanticLabels)->metadata(), 'keys' => []];

    expect(fn () => (new BaselineBacktester)->run($manifest, [], trainSize: 1, testSize: 1))
        ->toThrow(InvalidArgumentException::class, 'fee-aware research dataset');
});

it('uses strict thresholds and never interprets flat fee-free prices as an action', function () {
    expect((new LabelDefinition(1, 0, 0, 0))->label(100, 100)['action'])->toBe('hodl')
        ->and((new LabelDefinition(1, 0, 0, 5000))->label(100, 150)['action'])->toBe('hodl');
});

it('rejects invalid label parameters and prices', function () {
    expect(fn () => new LabelDefinition(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LabelDefinition(1, NAN))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LabelDefinition(1, -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LabelDefinition(1, 10000))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new LabelDefinition)->label(0, 100))->toThrow(InvalidArgumentException::class);
});

it('keeps an explicit feature order and drops missing values without zero imputation', function () {
    $keys = FeatureSchema::keys('custom', ['context.btc_dominance', 'trend.direction']);
    expect(FeatureSchema::vector(['features' => ['trend.direction' => -1, 'context.btc_dominance' => 0.6]], $keys))->toBe([0.6, -1])
        ->and(FeatureSchema::vector(['features' => ['trend.direction' => 1]], $keys))->toBeNull()
        ->and(count(FeatureSchema::keys('core')))->toBe(15)
        ->and(count(FeatureSchema::keys('technical')))->toBe(18)
        ->and(count(FeatureSchema::keys('full')))->toBe(18)
        ->and(fn () => FeatureSchema::keys('custom', ['label']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FeatureSchema::keys('custom', ['context.circulating_fraction']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FeatureSchema::keys('custom', ['trend.direction', 'trend.direction']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FeatureSchema::vector(['features' => ['trend.direction' => 2]], ['trend.direction']))->toThrow(InvalidArgumentException::class);
});

it('parses only explicit UTC dates or millisecond timestamps and rejects malformed options', function () {
    expect(ResearchInput::timestamp('2026-01-01'))->toBe(1767225600000)
        ->and(ResearchInput::timestamp('2026-01-01T00:00:00Z'))->toBe(1767225600000)
        ->and(ResearchInput::timestamp('1767225600000'))->toBe(1767225600000)
        ->and(fn () => ResearchInput::timestamp('2026-02-30'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResearchInput::timestamp('yesterday'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResearchInput::integer('1.5', 'Size'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResearchInput::bps('abc'))->toThrow(InvalidArgumentException::class);
});

it('clips only machine roundoff at normalized feature boundaries', function () {
    $keys = ['momentum.rsi_3'];
    expect(FeatureSchema::vector(['features' => ['momentum.rsi_3' => 1.0000000000000002]], $keys))->toBe([1])
        ->and(FeatureSchema::vector(['features' => ['momentum.rsi_3' => -1e-16]], $keys))->toBe([0])
        ->and(fn () => FeatureSchema::vector(['features' => ['momentum.rsi_3' => 1.01]], $keys))->toThrow(InvalidArgumentException::class);
});
