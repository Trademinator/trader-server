<?php

namespace App\Jobs;

use App\Domain\MarketData\CandlePeriodReevaluation;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketCatalogException;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Domain\Operations\ActionLog;
use App\Models\MarketFeed;
use App\Repositories\TickerRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

use function Trademinator\Time\periods_to_seconds;

final class CollectMarketFeed implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 240];

    public function __construct(public readonly string $marketId, public readonly string $leaseToken) {}

    public function handle(CandlePeriodReevaluation $reevaluation, MarketDataSynchronizer $synchronizer,
        TickerRepository $tickers, ExchangeMetadata $metadata): void
    {
        $lock = Cache::lock('trademinator:market-feed:'.$this->marketId, 720);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }
        try {
            $feed = MarketFeed::query()->with('market.exchange')->find($this->marketId);
            if ($feed === null || $feed->lease_token !== $this->leaseToken) {
                return; // Stale job from an expired or replaced claim.
            }
            if (! $feed->market->subscriptions()->where('active', true)->exists()) {
                $this->finish('idle', null);

                return;
            }
            $market = $feed->market;
            $exchange = $market->exchange;
            try {
                $metadata->assertUsable($exchange, spotOnly: false, symbol: $market->symbol);
            } catch (MarketCatalogException $exception) {
                $this->finish('blocked', now()->addHours(6), $exception->getMessage());

                return;
            }
            if ($feed->selected_period === null) {
                $result = $reevaluation->evaluate($feed);
                $feed->refresh();
                if ($feed->selected_period === null) {
                    $next = $feed->selection_next_attempt_at ?? now()->addMinutes(15);
                    $this->finish('pending', $next, $result['reason'] ?? 'Automatic candle-period evaluation is pending.');

                    return;
                }
            }
            $period = $feed->selected_period;
            if (! in_array($period, CandleTimeframe::SUPPORTED, true)) {
                throw new RuntimeException('Invalid stored candle period.');
            }
            $seconds = periods_to_seconds($period);
            $to = time() - $seconds; // Never rely on an unfinished candle.
            $latest = $tickers->latestTimestamp($exchange->class, $market->symbol, $period);
            $from = max($to - 90 * $seconds, $latest === null ? 0 : intdiv($latest, 1000) - 3 * $seconds);
            if ($from < $to) {
                $synchronizer->sync($exchange->class, $market->symbol, $period, $from, $to, false, false, 90);
            }
            $this->finish('ready', now()->addSeconds(max(60, $seconds)), null, true);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->finish('error', now()->addMinutes(5), $exception?->getMessage() ?? 'Collection failed.');
    }

    private function finish(string $status, mixed $next, ?string $error = null, bool $pulled = false): void
    {
        $updated = DB::table('market_feeds')->where('market_id', $this->marketId)->where('lease_token', $this->leaseToken)
            ->update(['status' => $status, 'next_pull_at' => $next,
                'last_pulled_at' => $pulled ? now() : DB::raw('last_pulled_at'),
                'last_error' => $error === null ? null : mb_substr($error, 0, 1000),
                'lease_until' => null, 'lease_token' => null, 'updated_at' => now()]);
        if ($updated) {
            app(ActionLog::class)->write('feed.updated', ['market_id' => $this->marketId,
                'status' => $status, 'outcome' => $status === 'error' ? 'failed' : 'completed']);
        }
    }
}
