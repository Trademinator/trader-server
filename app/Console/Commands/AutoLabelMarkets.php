<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\ActionLabelAnalysis;
use App\Models\MarketFeed;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class AutoLabelMarkets extends Command
{
    protected $signature = 'trademinator:auto-label
        {exchange? : Optional exchange class, for example bitso}
        {symbol? : Optional pair; requires exchange, for example ATOM/USD}
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Run Action auto-labeling and Outcome horizon diagnostics for active market feeds';

    public function handle(ActionLabelAnalysis $analysis): int
    {
        $exchange = $this->argument('exchange');
        $symbol = $this->argument('symbol');
        if ($symbol !== null && $exchange === null) {
            throw new InvalidArgumentException('A symbol requires an exchange.');
        }

        $feeds = MarketFeed::query()->with('market.exchange')
            ->whereNotNull('selected_period')
            ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))
            ->when($exchange !== null, fn ($query) => $query->whereHas('market.exchange',
                fn ($exchangeQuery) => $exchangeQuery->where('class', $exchange)))
            ->when($symbol !== null, fn ($query) => $query->whereHas('market',
                fn ($marketQuery) => $marketQuery->where('symbol', $symbol)))
            ->orderBy('market_id')
            ->get();

        if ($feeds->isEmpty()) {
            $this->error('No active subscribed market feed matches this scope.');

            return self::FAILURE;
        }

        $rows = [];
        $failed = false;
        $asOfMs = now()->getTimestampMs();
        foreach ($feeds as $feed) {
            try {
                $result = $analysis->analyze(
                    $feed->market->exchange->class,
                    $feed->market->symbol,
                    $feed->selected_period,
                    $asOfMs
                );
                $rows[] = [
                    'exchange' => $feed->market->exchange->class,
                    'symbol' => $feed->market->symbol,
                    'period' => $feed->selected_period,
                    ...ActionLabelAnalysis::publicDiagnostics($result),
                ];
            } catch (Throwable $error) {
                $failed = true;
                $rows[] = [
                    'exchange' => $feed->market->exchange->class,
                    'symbol' => $feed->market->symbol,
                    'period' => $feed->selected_period,
                    'status' => 'failed',
                    'error' => $error->getMessage(),
                ];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Exchange', 'Pair', 'Period', 'BUY', 'HOLD', 'SELL', 'Pivots', 'd', 'H', 'Status'],
                array_map(fn (array $row): array => [
                    $row['exchange'],
                    $row['symbol'],
                    $row['period'],
                    $row['action_counts']['buy'] ?? '-',
                    $row['action_counts']['hold'] ?? '-',
                    $row['action_counts']['sell'] ?? '-',
                    $row['pivot_count'] ?? '-',
                    isset($row['distance_observations'])
                        ? $row['distance_observations'].'/'.($row['minimum_distance_observations'] ?? ActionLabelAnalysis::DEFAULT_MIN_DISTANCE_OBSERVATIONS)
                        : '-',
                    $row['horizon'] ?? '-',
                    $row['status'].(isset($row['error']) ? ': '.$row['error'] : ''),
                ], $rows)
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
