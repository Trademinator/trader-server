<?php

use App\Domain\MarketData\CandleGaps;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Models\Exchange;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Database\Eloquent\Collection;

it('revisits the last candle and reports an observed gap', function () {
    $exchange = new Exchange(['name' => 'Demo', 'class' => 'kraken']);
    $exchanges = Mockery::mock(ExchangeRepository::class);
    $tickers = Mockery::mock(TickerRepository::class);
    $exchanges->shouldReceive('findByClass')->once()->with('kraken')->andReturn(new Collection([$exchange]));
    $exchanges->shouldReceive('setExchange')->once()->with($exchange);
    $exchanges->shouldReceive('periods')->once()->andReturn(['1m' => '1m']);
    $exchanges->shouldReceive('markets')->once()->andReturn(['BTC/USD' => []]);
    $tickers->shouldReceive('latestTimestamp')->once()->with('kraken', 'BTC/USD', '1m')->andReturn(60_000);
    $exchanges->shouldReceive('fetch')->once()->with('BTC/USD', '1m', 60, 180)->andReturn([['microtimestamp' => 60_000]]);
    $tickers->shouldReceive('timestamps')->once()->with('kraken', 'BTC/USD', '1m', 0, 180_000)->andReturn([0, 60_000, 180_000]);

    $result = (new MarketDataSynchronizer($exchanges, $tickers, new CandleGaps))
        ->sync('kraken', 'BTC/USD', '1m', 0, 180, true);

    expect($result)->toBe(['fetched' => 1, 'repaired' => 0, 'missing_ranges' => 1]);
});
