<?php

namespace App\Domain\Features;

use RuntimeException;

final class FeatureBuildLocked extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Features are already being built for this market and period.');
    }
}
