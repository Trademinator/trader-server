<?php

use App\Domain\Intelligence\ActionAutoLabeler;
use App\Domain\Intelligence\ActionLabelAnalysis;
use App\Domain\Intelligence\PublishedTakerFee;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\MarketCatalog;
use App\Repositories\TickerRepository;

function alternatingHistory(int $count, ?int $skip = null): array
{
    $rows = [];
    $start = 1_700_000_000_000;
    for ($i = 0; $i < $count; $i++) {
        if ($skip !== null && $i === $skip) {
            continue;
        }
        $up = $i % 2 === 0;
        $rows[$start + $i * 60_000] = [
            'open' => $up ? '100' : '110',
            'high' => '111',
            'low' => '99',
            'close' => $up ? '110' : '100',
            'volume' => '10',
        ];
    }

    return $rows;
}

function actionAnalysisFor(array $history): ActionLabelAnalysis
{
    $repository = Mockery::mock(TickerRepository::class);
    $repository->shouldReceive('streamHistory')->once()->andReturnUsing(function () use ($history): Generator {
        foreach ($history as $timestamp => $candle) {
            yield $timestamp => $candle;
        }
    });
    $catalog = app(MarketCatalog::class);

    return new ActionLabelAnalysis(
        $repository,
        new ActionAutoLabeler,
        new PublishedTakerFee($catalog),
        new CandleTimeframe,
    );
}

beforeEach(function () {
    config([
        'intelligence.max_model_age_days' => 366,
        'intelligence.min_horizon_distance_observations' => 30,
        'exchange_fees.taker_overrides.kraken.rate' => 0.0,
    ]);
});

it('requires thirty valid d observations but uses every available observation', function () {
    $insufficient = actionAnalysisFor(alternatingHistory(33))->analyze('kraken', 'BTC/USD', '1m', 1_800_000_000_000);
    $valid = actionAnalysisFor(alternatingHistory(100))->analyze('kraken', 'BTC/USD', '1m', 1_800_000_000_000);

    expect($insufficient['distance_observations'])->toBeLessThan(30)
        ->and($insufficient['horizon'])->toBeNull()
        ->and($insufficient['status'])->toBe('insufficient_distance_observations')
        ->and($valid['distance_observations'])->toBeGreaterThan(30)
        ->and($valid['distance_frequencies'][1])->toBe($valid['distance_observations'])
        ->and($valid['horizon'])->toBe(1)
        ->and($valid['status'])->toBe('validated');
});

it('never lets a d observation cross a missing candle', function () {
    $analysis = actionAnalysisFor(alternatingHistory(100, 50))
        ->analyze('kraken', 'BTC/USD', '1m', 1_800_000_000_000);

    expect($analysis['gaps'])->toBe(1)
        ->and($analysis['contiguous_runs'])->toBe(2)
        ->and($analysis['distance_max'])->toBe(1)
        ->and($analysis['horizon'])->toBe(1);
});
