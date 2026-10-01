<?php

use App\Traits\CandleAutoDetection;

function candleAutoDetector(): object
{
    return new class
    {
        use CandleAutoDetection;
    };
}

function autoCandle(string $open, string $close, string $high = '12', string $low = '8'): array
{
    return ['open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => '1'];
}

it('preserves the broad-label then prune ordering and keeps only the last consecutive action', function () {
    $tickers = [
        autoCandle('10', '9'), autoCandle('10', '9'), autoCandle('10', '9'), autoCandle('10', '9'),
        autoCandle('9', '10'), autoCandle('9', '10'), autoCandle('9', '10'),
    ];
    $detector = candleAutoDetector();
    $detector->candle_anatomy($tickers);
    $detector->mark_all_blacks_and_whites($tickers);
    expect(array_column($tickers, 'action'))->not->toBeEmpty();
    $detector->remove_consequitive_actions($tickers);

    $buys = array_keys(array_filter($tickers, fn (array $ticker): bool => ($ticker['action'] ?? null) === 'buy'));
    expect($buys)->toBe([3]);
});

it('uses only the transaction-cost floor in remove_unprofitable_transactions', function () {
    $tickers = [
        [...autoCandle('101', '100'), 'action' => 'buy'],
        [...autoCandle('100', '100.05'), 'action' => 'sell'],
        autoCandle('100', '100'), autoCandle('100', '100'),
    ];
    candleAutoDetector()->remove_unprofitable_transactions($tickers, '0.001');

    expect($tickers[0])->not->toHaveKey('action')
        ->and($tickers[1])->not->toHaveKey('action');
});


it('requires movement strictly greater than twice the one-side taker fee', function () {
    $atFloor = [
        [...autoCandle('101', '100'), 'action' => 'buy'],
        [...autoCandle('100', '100.2'), 'action' => 'sell'],
    ];
    candleAutoDetector()->remove_unprofitable_transactions($atFloor, '0.001');
    expect($atFloor[0])->not->toHaveKey('action')
        ->and($atFloor[1])->not->toHaveKey('action');

    $aboveFloor = [
        [...autoCandle('101', '100'), 'action' => 'buy'],
        [...autoCandle('100', '100.21'), 'action' => 'sell'],
    ];
    candleAutoDetector()->remove_unprofitable_transactions($aboveFloor, '0.001');
    expect($aboveFloor[0]['action'])->toBe('buy')
        ->and($aboveFloor[1]['action'])->toBe('sell');
});

it('labels only completely flat dojis as HOLD in the doji cleanup pass', function () {
    $tickers = [
        autoCandle('10', '10', '11', '9'),
        autoCandle('10', '10', '10', '10'),
    ];
    $detector = candleAutoDetector();
    $detector->candle_anatomy($tickers);
    $detector->hodl_all_dojis($tickers);

    expect($tickers[0])->not->toHaveKey('action')
        ->and($tickers[1]['action'])->toBe('hold');
});
