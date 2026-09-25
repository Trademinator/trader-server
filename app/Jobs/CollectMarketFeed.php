<?php

namespace App\Jobs;

use App\Domain\MarketData\CandlePeriodSelector;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Models\MarketFeed;
use App\Repositories\ExchangeRepository;
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

    public function handle(CandlePeriodSelector $selector, ExchangeRepository $exchanges,
        MarketDataSynchronizer $synchronizer, TickerRepository $tickers): void
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
            if ($feed->selected_period === null) {
                $exchanges->setExchange($exchange);
                $periods = array_values(array_intersect(
                    ['1m', '3m', '5m', '15m', '30m', '1h', '4h', '1d'],
                    array_keys($exchanges->periods())));
                if (! $periods) {
                    throw new RuntimeException('The exchange has no supported candle periods.');
                }
                // Fetch enough completed history per candidate to calculate quality.
                // A market with no adequate sample stays pending for a later retry.
                $selected = null;
                foreach ($periods as $period) {
                    $to = time();
                    $from = $to - min(365 * 86400, 260 * periods_to_seconds($period));
                    $selected = $selector->select($exchange->class, $market->symbol, [$period],
                        (float) $market->tick_size, $from, $to);
                    if ($selected !== null) {
                        break;
                    }
                }
                if ($selected === null) {
                    $this->finish('pending', now()->addMinutes(15), 'No candle period meets the quality and coverage thresholds yet.');

                    return;
                }
                $feed->selected_period = $selected['period'];
                // Save the choice before ingestion so a failed request does not redo selection.
                MarketFeed::query()->whereKey($this->marketId)->where('lease_token', $this->leaseToken)
                    ->update(['selected_period' => $selected['period']]);
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
        DB::table('market_feeds')->where('market_id', $this->marketId)->where('lease_token', $this->leaseToken)
            ->update(['status' => $status, 'next_pull_at' => $next,
                'last_pulled_at' => $pulled ? now() : DB::raw('last_pulled_at'),
                'last_error' => $error === null ? null : mb_substr($error, 0, 1000),
                'lease_until' => null, 'lease_token' => null, 'updated_at' => now()]);
    }
}
