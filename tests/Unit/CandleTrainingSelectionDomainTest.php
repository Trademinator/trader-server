<?php

use Symfony\Component\Process\Process;

it('bounds candidate source checks and keeps revised snapshots unlabelled', function () {
    $process = new Process([PHP_BINARY, 'tests/Support/candle-training-selection-checks.php'], dirname(__DIR__, 2));
    $process->setTimeout(30)->mustRun();
    expect($process->getOutput())->toContain('14/14 selector checks passed');
});
