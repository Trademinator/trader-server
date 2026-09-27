<?php

use Symfony\Component\Process\Process;

it('renders the full exchange list, decodes 5000 Binance pairs and subscribes under 128 MB', function () {
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', 'tests/Support/market-catalog-memory.php'], dirname(__DIR__, 2));
    $process->setTimeout(60)->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['memory_limit'])->toBe('128M')
        ->and($result['pairs'])->toBe(5000)
        ->and($result['requests'])->toBe(1)
        ->and($result['peak_bytes'])->toBeLessThan(128 * 1024 * 1024)
        ->and($result['list_peak_bytes'])->toBeLessThan(64 * 1024 * 1024);
});
