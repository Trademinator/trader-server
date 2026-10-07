<?php

namespace App\Domain\Intelligence;

use RuntimeException;

final class IntelligenceNotReady extends RuntimeException
{
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct('Automatic intelligence is not ready: '.($diagnostics['reason'] ?? 'insufficient_history').'.');
    }
}
