<?php

use App\Domain\Archive\ArchiveCatalog;
use Illuminate\Database\QueryException;

function archiveCatalogQueryException(int $driverCode): QueryException
{
    $previous = new PDOException('simulated database contention');
    $previous->errorInfo = ['HY000', $driverCode, 'simulated database contention'];

    return new QueryException('mariadb', 'insert into archive_catalog ...', [], $previous);
}

function invokeArchiveCatalogRetry(callable $callback, int $attempts = 5): void
{
    $method = new ReflectionMethod(ArchiveCatalog::class, 'retryConcurrentWrite');
    $method->invoke(app(ArchiveCatalog::class), $callback, $attempts);
}

it('retries transient archive catalog concurrency errors', function (int $driverCode) {
    $calls = 0;

    invokeArchiveCatalogRetry(function () use (&$calls, $driverCode): void {
        $calls++;

        if ($calls === 1) {
            throw archiveCatalogQueryException($driverCode);
        }
    }, 2);

    expect($calls)->toBe(2);
})->with([
    'record changed since last read' => 1020,
    'lock wait timeout' => 1205,
    'deadlock' => 1213,
]);

it('does not retry non-transient archive catalog query errors', function () {
    $state = (object) ['calls' => 0];

    expect(fn () => invokeArchiveCatalogRetry(function () use ($state): void {
        $state->calls++;

        throw archiveCatalogQueryException(1062);
    }, 2))->toThrow(QueryException::class);

    expect($state->calls)->toBe(1);
});
