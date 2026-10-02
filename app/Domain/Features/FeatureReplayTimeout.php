<?php

namespace App\Domain\Features;

use RuntimeException;

final class FeatureReplayTimeout extends RuntimeException
{
    public function __construct(public readonly ?int $throughMs = null)
    {
        parent::__construct('Feature replay exceeded 540 seconds; split the remaining work into smaller queue jobs.');
    }
}
