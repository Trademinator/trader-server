<?php

namespace App\Jobs;

use App\Domain\Features\FeatureBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class BuildMarketFeatures implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public array $backoff = [60, 300];

    public function __construct(public string $exchange, public string $symbol, public string $period) {}

    public function uniqueId(): string
    {
        return hash('sha256', "$this->exchange|$this->symbol|$this->period");
    }

    public function handle(FeatureBuilder $builder): void
    {
        $builder->build($this->exchange, $this->symbol, $this->period);
    }
}
