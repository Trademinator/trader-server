<?php

use App\Domain\MarketData\CandleReconstructor;
use App\Models\Exchange;
use App\Models\Ticker;
use App\Repositories\ExchangeRepository;
use ccxt\bitso;

/** Exercise CCXT's real JSON number quoting, not an already-normalized mock array. */
function bitsoMetadataRepository(array $overrides = [], array $omit = []): ExchangeRepository
{
    $at = 1780646400000;
    $raw = array_diff_key(array_replace(['bucket_start_time' => $at,
        'first_rate' => '1.747000000000000001', 'max_rate' => '1.747000000000000001',
        'min_rate' => '1.747000000000000001', 'last_rate' => '1.747000000000000001',
        'volume' => '0.364015430000000001', 'trade_count' => 1,
        'first_trade_time' => $at + 1000, 'last_trade_time' => $at + 2000], $overrides), array_flip($omit));
    $client = Mockery::mock(bitso::class)->makePartial();
    $client->timeframes = ['30m' => '1800'];
    $client->shouldReceive('market')->once()->with('ATOM/USD')->andReturn(['id' => 'atom_usd']);
    $json = json_encode(['success' => true, 'payload' => [$raw]], JSON_THROW_ON_ERROR);
    $client->shouldReceive('request')->once()->andReturn($client->parse_json($json));
    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['class' => 'bitso']));
    $reflection->getProperty('ccxtExchange')->setValue($repository, $client);

    return $repository;
}

it('normalizes quoted count and time metadata while preserving exact prices and volume', function () {
    $rows = bitsoMetadataRepository()->fetchCandleEvidence('ATOM/USD', '30m', 1780646400000, 1780648200000, 1);

    expect($rows[0])->toMatchArray(['microtimestamp' => 1780646400000, 'trade_count' => 1,
        'first_trade_time' => 1780646401000, 'last_trade_time' => 1780646402000,
        'open' => '1.747000000000000001', 'volume' => '0.364015430000000001']);
    $this->assertDatabaseCount('tickers', 0);
});

it('preserves absent optional metadata instead of inventing null fields', function () {
    $rows = bitsoMetadataRepository(omit: ['trade_count', 'first_trade_time', 'last_trade_time'])
        ->fetchCandleEvidence('ATOM/USD', '30m', 1780646400000, 1780648200000, 1);

    expect($rows[0])->not->toHaveKeys(['trade_count', 'first_trade_time', 'last_trade_time']);
});

it('preserves explicit nulls and converts a zero count without inventing trades', function () {
    $rows = bitsoMetadataRepository(['trade_count' => 0, 'volume' => '0', 'first_trade_time' => null, 'last_trade_time' => null])
        ->fetchCandleEvidence('ATOM/USD', '30m', 1780646400000, 1780648200000, 1);

    expect($rows[0])->toMatchArray(['trade_count' => 0, 'first_trade_time' => null, 'last_trade_time' => null]);
});

it('rejects lossy or malformed metadata instead of casting it to an integer', function (mixed $value) {
    $repository = bitsoMetadataRepository(['trade_count' => $value]);

    expect(fn () => $repository->fetchCandleEvidence('ATOM/USD', '30m', 1780646400000, 1780648200000, 1))
        ->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('tickers', 0);
})->with(['fraction' => ['1.5'], 'exponent' => ['1e3'], 'negative' => [-1], 'boolean' => [true],
    'blank' => [''], 'whitespace' => [' 1'], 'overflow' => [(string) PHP_INT_MAX.'0'], 'object' => [['count' => 1]]]);

it('accepts quoted metadata through parent reconstruction while retaining volume reconciliation', function (bool $conflictingVolume) {
    $this->travelTo('2026-06-05 10:00:00 UTC');
    $at = 1780646400000;
    $client = Mockery::mock(bitso::class)->makePartial();
    $client->timeframes = ['15m' => '900', '30m' => '1800'];
    $client->shouldReceive('market')->twice()->with('ATOM/USD')->andReturn(['id' => 'atom_usd']);
    $parent = ['bucket_start_time' => $at, 'first_rate' => '10', 'last_rate' => '10', 'min_rate' => '10',
        'max_rate' => '10', 'volume' => $conflictingVolume ? '4.000000000000000001' : '4', 'trade_count' => 2,
        'first_trade_time' => $at + 901000, 'last_trade_time' => $at + 902000];
    $sibling = [...$parent, 'bucket_start_time' => $at + 900000, 'volume' => '4'];
    $client->shouldReceive('request')->twice()->andReturn(
        $client->parse_json(json_encode(['success' => true, 'payload' => [$parent]])),
        $client->parse_json(json_encode(['success' => true, 'payload' => [$sibling]])));
    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['class' => 'bitso']));
    $reflection->getProperty('ccxtExchange')->setValue($repository, $client);
    foreach ([$at - 900000, $at + 900000] as $time) {
        Ticker::query()->create(['exchange' => 'bitso', 'symbol' => 'ATOM/USD', 'period' => '15m',
            'microtimestamp' => $time, 'payload' => json_encode(['open' => '10', 'high' => '10', 'low' => '10', 'close' => '10', 'volume' => '4'])]);
    }

    $result = app(CandleReconstructor::class)->attempt($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs());

    expect($result['diagnostics']['reason'])->toBe($conflictingVolume ? 'parent_volume_mismatch' : 'reconstructed_no_trades');
    if (! $conflictingVolume) {
        expect($result['candle']['reconstruction']['verification'])->toBe('parent_ohlcv_and_trade_counts');
    }
    $this->assertDatabaseCount('tickers', 2);
})->with([false, true]);

it('rejects malformed trade timestamps without truncating them', function (string $field) {
    $repository = bitsoMetadataRepository([$field => '1780646401000.5']);

    expect(fn () => $repository->fetchCandleEvidence('ATOM/USD', '30m', 1780646400000, 1780648200000, 1))
        ->toThrow(RuntimeException::class);
})->with(['first_trade_time', 'last_trade_time']);

it('normalizes zero-padded decimal integer metadata without treating it as octal', function () {
    $rows = bitsoMetadataRepository(['trade_count' => '0008'])
        ->fetchCandleEvidence('ATOM/USD', '30m', 1780646400000, 1780648200000, 1);

    expect($rows[0]['trade_count'])->toBe(8);
});
