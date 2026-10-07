<?php

use App\Domain\MarketData\CandleGapRepairs;
use Illuminate\Database\QueryException;

function candleGapQueryException(int $driverCode): QueryException
{
    $previous = new PDOException('simulated database contention');
    $previous->errorInfo = ['HY000', $driverCode, 'simulated database contention'];

    return new QueryException('mariadb', 'update candle_gap_repairs ...', [], $previous);
}

function invokeCandleGapRetry(callable $callback, int $attempts = 3): void
{
    $repairs = (new ReflectionClass(CandleGapRepairs::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CandleGapRepairs::class, 'retryConcurrentWrite');
    $method->invoke($repairs, $callback, $attempts);
}

it('retries transient gap bookkeeping concurrency errors', function (int $driverCode) {
    $calls = 0;

    invokeCandleGapRetry(function () use (&$calls, $driverCode): void {
        $calls++;

        if ($calls === 1) {
            throw candleGapQueryException($driverCode);
        }
    }, 2);

    expect($calls)->toBe(2);
})->with([
    'record changed since last read' => 1020,
    'lock wait timeout' => 1205,
    'deadlock' => 1213,
]);

it('does not retry non-transient gap bookkeeping query errors', function () {
    $state = (object) ['calls' => 0];

    expect(fn () => invokeCandleGapRetry(function () use ($state): void {
        $state->calls++;

        throw candleGapQueryException(1062);
    }, 2))->toThrow(QueryException::class);

    expect($state->calls)->toBe(1);
});
