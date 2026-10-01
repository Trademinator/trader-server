<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleTimeframe;
use App\Models\Market;

final class CollectionAttention
{
    /** @return list<array{message: string, commands: list<string>, last_closed_at_ms?: int|null}> */
    public function describe(Market $market, array $chart): array
    {
        $issues = [];
        $feed = $market->feed;
        $worker = 'php -d memory_limit=512M artisan queue:work --queue=default --stop-when-empty --max-time=50 --timeout=600 --memory=384 --tries=5';
        $dispatch = 'php artisan trademinator:dispatch-market-feeds';
        if ($feed === null) {
            return [['message' => 'The shared collection feed is missing. Re-enable this market subscription in Manage subscriptions.', 'commands' => []]];
        }
        $queuedLeaseExpired = $feed->status === 'queued'
            && ($feed->lease_until === null || $feed->lease_until->isPast());
        $periodProblemExplained = false;

        if ($queuedLeaseExpired) {
            $message = 'The collection job lease expired before completion. Queue a replacement and drain the default queue.';
            if ($feed->last_error !== null) {
                $message .= ' Previous attempt: '.$feed->last_error;
            }
            $issues[] = ['message' => $message, 'commands' => [$dispatch, $worker]];
            $periodProblemExplained = $feed->selected_period === null;
        } elseif ($feed->last_error !== null) {
            $commands = $feed->status === 'blocked'
                ? ['php artisan trademinator:refresh-exchanges --check']
                : ($feed->selected_period === null ? [$dispatch, $worker] : ['php artisan queue:failed']);
            $issues[] = ['message' => 'Collector '.$feed->status.': '.$feed->last_error, 'commands' => $commands];
            $periodProblemExplained = $feed->selected_period === null;
        }

        if ($feed->status === 'queued' && ! $queuedLeaseExpired) {
            if ($chart['stale']) {
                $issues[] = ['message' => 'Collection is queued while history is stale. Drain the default queue if no worker is processing it.',
                    'commands' => [$worker]];
            }
        } elseif (! in_array($feed->status, ['ready', 'active', 'pending', 'queued'], true)) {
            $issues[] = ['message' => 'Collection status: '.$feed->status.'. Resolve the reported exchange/configuration problem first; dispatch retries when they are due.',
                'commands' => [$dispatch, $worker]];
        }

        if ($feed->selected_period === null) {
            if (! $periodProblemExplained) {
                $issues[] = ['message' => 'No reliable candle period has been selected. Run due collection; a quiet or unsupported market may still fail the quality threshold.',
                    'commands' => [$dispatch, $worker]];
            }
        } elseif ($chart['stale']) {
            $last = $chart['last_closed_at_ms'];
            $issues[] = ['message' => $last === null ? 'No valid closed '.$feed->selected_period.' candles are available.'
                : 'Price history is stale.',
                'last_closed_at_ms' => $last,
                'commands' => [$dispatch, $worker]];
        }
        if ($chart['gaps'] > 0 || $chart['invalid_candles'] > 0) {
            $series = $chart['series'];
            $fromMs = ($series[0]['time'] ?? now()->timestamp) * 1000;
            for ($i = 0; $i < 49; $i++) {
                $fromMs = (new CandleTimeframe)->previous($fromMs, $feed->selected_period);
            }
            $from = gmdate('Y-m-d\TH:i:s\Z', intdiv(max(0, $fromMs), 1000));
            $arguments = implode(' ', array_map('escapeshellarg', [$market->exchange->class, $market->symbol, $feed->selected_period]));
            $issues[] = ['message' => $chart['gaps'].' gaps and '.$chart['invalid_candles'].' invalid candles in the recent history window. Re-fetch this range in queued pages. Exchanges may omit intervals with no trades.',
                'commands' => ['php artisan trademinator:sync-ohlcv '.$arguments.' --from='.escapeshellarg($from).' --to=now --repair-gaps --queue', $worker]];
        }

        return $issues;
    }
}
