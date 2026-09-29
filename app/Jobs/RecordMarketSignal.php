<?php

namespace App\Jobs;

use App\Domain\Intelligence\SignalJournal;
use App\Models\Market;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecordMarketSignal implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public array $backoff = [60];

    public function __construct(public string $marketId) {}

    public function uniqueId(): string
    {
        return $this->marketId;
    }

    public function handle(SignalJournal $journal): void
    {
        $market = Market::query()->with('feed', 'exchange')->find($this->marketId);
        if ($market !== null && config('dashboard.signals_enabled') && config('intelligence.enabled')) {
            $journal->record($market);
        }
    }
}
