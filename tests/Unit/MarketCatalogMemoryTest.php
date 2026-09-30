<?php

use Symfony\Component\Process\Process;

function runMarketMemoryScenario(array $arguments = []): array
{
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', 'tests/Support/market-catalog-memory.php', ...$arguments], dirname(__DIR__, 2));
    $process->setTimeout(60)->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('renders the full exchange list, decodes 5000 Binance pairs and subscribes under 128 MB', function () {
    $result = runMarketMemoryScenario();
    expect($result['memory_limit'])->toBe('128M')
        ->and($result['pairs'])->toBe(5000)
        ->and($result['requests'])->toBe(1)
        ->and($result['peak_bytes'])->toBeLessThan(128 * 1024 * 1024)
        ->and($result['list_peak_bytes'])->toBeLessThan(64 * 1024 * 1024);
});

it('collects repeatedly and builds features against a 5000-pair exchange under 128 MB', function () {
    $result = runMarketMemoryScenario(['--collector']);
    expect($result['memory_limit'])->toBe('128M')
        ->and($result['requests'])->toBe(1)
        ->and($result['peak_bytes'])->toBeLessThan(128 * 1024 * 1024)
        ->and($result['ohlcv_requests'])->toBeGreaterThan(0)
        ->and($result['features'])->toBeGreaterThan(200)
        ->and($result['largest_candle_request'])->toBeLessThanOrEqual(100);
});
