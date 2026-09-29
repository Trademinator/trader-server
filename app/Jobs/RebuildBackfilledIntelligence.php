<?php

namespace App\Jobs;

use App\Domain\Features\FeatureBuilder;
use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Models\MarketFeed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class RebuildBackfilledIntelligence implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $maxExceptions = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $historyId, public readonly string $leaseToken) {}

    public function handle(BackfillIntelligence $builds, FeatureBuilder $features, MarketIntelligence $intelligence): void
    {
        $state = $builds->owned($this->historyId, $this->leaseToken)->first();
        if ($state === null) {
            return;
        }
        $feed = MarketFeed::query()->with('market.exchange')->find($state->market_id);
        if (! config('history_backfill.enabled') || ! config('intelligence.enabled') || $feed === null
            || $feed->selected_period !== $state->period || ! $feed->market->subscriptions()->where('active', true)->exists()) {
            $builds->release($this->historyId, $this->leaseToken, ['build_error' => 'Waiting for an active feed and enabled intelligence.'], 60);

            return;
        }
        $exchange = $feed->market->exchange->class;
        $symbol = $feed->market->symbol;
        $key = ModelStore::marketKey($exchange, $symbol, $state->period);
        $lock = Cache::lock('trademinator:history-intelligence:'.$key, 720);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }
        try {
            if ($state->build_stage === 'features') {
                // Fold in every completed import that arrived before this job started.
                // Imports arriving during the rebuild remain dirty for a later pass.
                $revision = (int) $state->history_revision;
                $features->build($exchange, $symbol, $state->period);
                $builds->release($this->historyId, $this->leaseToken, [
                    'build_stage' => 'knn', 'build_revision' => $revision, 'build_error' => null,
                ]);
            } elseif ($state->build_stage === 'knn' && (int) $state->build_revision > 0) {
                $revision = (int) $state->build_revision;
                $report = $intelligence->build($exchange, $symbol, $state->period, schema: config('intelligence.schema'),
                    generation: hash('sha256', 'history:'.$this->historyId.':'.$revision));
                $builds->release($this->historyId, $this->leaseToken, [
                    'trained_revision' => $revision, 'build_stage' => null, 'build_revision' => null,
                    'build_failures' => 0, 'build_error' => null, 'model_id' => $report['model_id'], 'last_trained_at' => now(),
                ]);
            } else {
                throw new RuntimeException('Invalid backfill intelligence checkpoint.');
            }
            $builds->dispatchDue($exchange, $symbol, $state->period);
        } catch (Throwable $error) {
            $builds->failure($this->historyId, $this->leaseToken, $error);
            report($error);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(BackfillIntelligence::class)->failure($this->historyId, $this->leaseToken,
            $exception ?? new RuntimeException('Backfill intelligence worker failed.'));
    }
}
