<?php

use Symfony\Component\Process\Process;

it('passes the standalone intelligence recovery domain regressions', function () {
    $process = new Process([PHP_BINARY, 'tests/Support/intelligence-recovery-checks.php'], dirname(__DIR__, 2));
    $process->setTimeout(30)->mustRun();
    expect($process->getOutput())->toContain('46/46 domain cases passed');
});
