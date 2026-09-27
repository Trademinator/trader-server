<?php

use App\Domain\Research\FeatureSchema;
use App\Domain\Research\LabelDefinition;
use App\Domain\Research\ResearchInput;

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
        ->and(count(FeatureSchema::keys('full')))->toBe(28)
        ->and(fn () => FeatureSchema::keys('custom', ['label']))->toThrow(InvalidArgumentException::class)
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
