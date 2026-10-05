<?php

namespace App\Console\Commands;

use App\Domain\MarketData\CandlePeriodReevaluation;
use App\Helpers\Decimal;
use App\Models\MarketFeed;
use Illuminate\Console\Command;
use Throwable;

final class EvaluateCandlePeriod extends Command
{
    protected $signature = 'trademinator:evaluate-candle-period {--exchange=} {--pair=} {--dry-run} {--outdated-only}';

    protected $description = 'Evaluate and optionally update automatic candle periods for subscribed markets';

    public function handle(CandlePeriodReevaluation $evaluation): int
    {
        $exchange = $this->option('exchange');
        $pair = $this->option('pair');
        $dryRun = (bool) $this->option('dry-run');
        $outdatedOnly = (bool) $this->option('outdated-only');
        $version = max(1, (int) config('candle_period.selection_version', 2));

        $query = MarketFeed::query()->with('market.exchange')
            ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))
            ->when($exchange !== null, fn ($query) => $query->whereHas('market.exchange',
                fn ($query) => $query->where('class', $exchange)))
            ->when($pair !== null, fn ($query) => $query->whereHas('market',
                fn ($query) => $query->where('symbol', $pair)));

        if ($outdatedOnly) {
            $query->where(fn ($query) => $query->whereNull('selected_period')->orWhere('selection_version', '<', $version))
                ->where(fn ($query) => $query->whereNull('selection_next_attempt_at')->orWhere('selection_next_attempt_at', '<=', now()));
        }

        $maximumFeeds = $outdatedOnly
            ? max(1, (int) config('candle_period.scheduled_markets_per_run', 10))
            : null;
        $rows = [];
        $failed = false;
        $processed = 0;
        foreach ($query->orderBy('market_id')->lazy(5) as $feed) {
            if ($maximumFeeds !== null && $processed >= $maximumFeeds) {
                break;
            }
            $processed++;
            try {
                $result = $evaluation->evaluate($feed, $dryRun, ! $outdatedOnly);
                $rows[] = [
                    $result['exchange'], $result['symbol'], $result['current_period'] ?? '-',
                    $result['selected_period'] ?? '-', $result['status'],
                    $result['window_days'] ?? '-', $this->percent($result['buy_ratio']),
                    $this->percent($result['sell_ratio']), $result['reason'],
                ];
            } catch (Throwable $error) {
                report($error);
                $failed = true;
                $rows[] = [
                    $feed->market->exchange->class, $feed->market->symbol, $feed->selected_period ?? '-', '-',
                    'error', '-', '-', '-', $error->getMessage(),
                ];
            } finally {
                $feed->unsetRelations();
                gc_collect_cycles();
                gc_mem_caches();
            }
        }

        if ($rows === []) {
            $this->info('No active subscribed markets match the requested filters.');

            return self::SUCCESS;
        }

        $this->table(['Exchange', 'Pair', 'Current', 'Result', 'Status', 'Days', 'BUY', 'SELL', 'Reason'], $rows);
        if ($dryRun) {
            $this->info('Dry run: no period was changed and no history backfill was queued.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function percent(mixed $ratio): string
    {
        return is_numeric($ratio) ? Decimal::format((float) $ratio * 100, 2).'%' : '-';
    }
}
