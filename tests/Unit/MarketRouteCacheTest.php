<?php

use Symfony\Component\Process\Process;

it('restores new market routes after clearing or rebuilding a stale isolated route cache', function () {
    $process = new Process([PHP_BINARY, 'tests/Support/market-route-cache.php'], dirname(__DIR__, 2));
    $process->setTimeout(30)->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result)->toMatchArray(['stale_cache_reproduced' => true, 'route_clear_restored_routes' => true,
        'rebuilt_cache_restored_routes' => true, 'authentication_preserved' => true, 'database' => ':memory:']);
});
