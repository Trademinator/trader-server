<?php

namespace App\Domain\Research;

use InvalidArgumentException;
use RuntimeException;

final class BaselineBacktester
{
    public const STRATEGIES = ['majority', 'trend', 'buy', 'hodl'];

    public function run(array $manifest, array $rows, string $strategy = 'majority', int $trainSize = 500,
        int $testSize = 100, int $gap = 0, bool $expanding = true): array
    {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new InvalidArgumentException('Strategy must be majority, trend, buy, or hodl.');
        }
        $trendColumn = array_search('trend.direction', $manifest['keys'], true);
        if ($strategy === 'trend' && $trendColumn === false) {
            throw new InvalidArgumentException('The trend baseline requires trend.direction in the dataset schema.');
        }
        $folds = [];
        $predictions = [];
        $confusion = $this->emptyConfusion();
        $portfolio = $this->portfolio();
        $benchmark = $this->portfolio();
        foreach ((new WalkForward)->folds($rows, $trainSize, $testSize, $gap, $expanding) as $fold) {
            // Ties resolve conservatively to HODL, then BUY, then SELL.
            $counts = array_fill_keys(['hodl', 'buy', 'sell'], 0);
            foreach ($fold['train'] as $index) {
                $counts[$rows[$index]['label']]++;
            }
            $majority = array_search(max($counts), $counts, true);
            $matrix = $this->emptyConfusion();
            foreach ($fold['test'] as $index) {
                $row = $rows[$index];
                $prediction = match ($strategy) {
                    'majority' => $majority,
                    'trend' => match ($row['vector'][$trendColumn] <=> 0) {
                        1 => 'buy', -1 => 'sell', default => 'hodl'
                    },
                    default => $strategy,
                };
                $matrix[$row['label']][$prediction]++;
                $confusion[$row['label']][$prediction]++;
                $traded = $this->execute($portfolio, $row, $prediction);
                $this->execute($benchmark, $row, 'buy');
                $predictions[] = ['row' => $index, 'fold' => $fold['fold'], 'decision_at_ms' => $row['decision_at_ms'],
                    'prediction' => $prediction, 'actual' => $row['label'], 'executed_buy' => $traded];
            }
            $firstTrain = $rows[$fold['train'][0]];
            $lastTrain = $rows[$fold['train'][array_key_last($fold['train'])]];
            $firstTest = $rows[$fold['test'][0]];
            $lastTest = $rows[$fold['test'][array_key_last($fold['test'])]];
            $folds[] = ['fold' => $fold['fold'], 'train_rows' => count($fold['train']), 'test_rows' => count($fold['test']),
                'train_from_ms' => $firstTrain['decision_at_ms'], 'train_to_ms' => $lastTrain['decision_at_ms'],
                'train_labels_available_by_ms' => max(array_map(fn ($i) => $rows[$i]['label_available_at_ms'], $fold['train'])),
                'test_from_ms' => $firstTest['decision_at_ms'], 'test_to_ms' => $lastTest['decision_at_ms'],
                'purged_or_gap_rows' => $fold['purged_or_gap_rows'], 'train_label_counts' => $counts,
                'majority_label' => $majority, 'classification' => $this->metrics($matrix)];
        }
        if ($folds === []) {
            throw new RuntimeException('Insufficient mature rows for a purged training window and a test window. Reduce --train or collect more history.');
        }

        return ['version' => 'm3-backtest-v1', 'dataset_id' => $manifest['dataset_id'],
            'dataset_sha256' => $manifest['rows_sha256'], 'feature_version' => $manifest['feature_version'],
            'keys' => $manifest['keys'], 'label_definition' => $manifest['label_definition'],
            'parameters' => ['strategy' => $strategy, 'train' => $trainSize, 'test' => $testSize,
                'gap' => $gap, 'window' => $expanding ? 'expanding' : 'rolling'],
            'classification' => $this->metrics($confusion), 'portfolio' => $this->finish($portfolio),
            'always_buy_benchmark' => $this->finish($benchmark), 'folds' => $folds, 'predictions' => $predictions,
            'assumptions' => ['spot_long_only', 'initial_cash_one_quote_unit', 'all_cash_per_buy',
                'fixed_horizon_exit', 'sell_and_hodl_stay_in_cash', 'ignore_signals_during_open_trade',
                'fees_and_slippage_each_side', 'drawdown_at_realized_exits_only', 'no_orderbook_or_latency_model'],
        ];
    }

    private function emptyConfusion(): array
    {
        return array_fill_keys(['buy', 'sell', 'hodl'], array_fill_keys(['buy', 'sell', 'hodl'], 0));
    }

    private function metrics(array $matrix): array
    {
        $total = $correct = 0;
        $classes = [];
        foreach ($matrix as $label => $predicted) {
            $support = array_sum($predicted);
            $tp = $predicted[$label];
            $predictedCount = array_sum(array_column($matrix, $label));
            $precision = $predictedCount === 0 ? 0.0 : $tp / $predictedCount;
            $recall = $support === 0 ? 0.0 : $tp / $support;
            $classes[$label] = ['support' => $support, 'precision' => $precision, 'recall' => $recall,
                'f1' => $precision + $recall == 0 ? 0.0 : 2 * $precision * $recall / ($precision + $recall)];
            $total += $support;
            $correct += $tp;
        }

        return ['samples' => $total, 'accuracy' => $total === 0 ? 0.0 : $correct / $total,
            'macro_f1' => array_sum(array_column($classes, 'f1')) / 3, 'classes' => $classes,
            'confusion_matrix_actual_by_predicted' => $matrix];
    }

    private function portfolio(): array
    {
        return ['equity' => 1.0, 'peak' => 1.0, 'max_drawdown' => 0.0, 'trades' => 0, 'wins' => 0,
            'busy_until_ms' => 0, 'overlapping_buy_signals_skipped' => 0, 'equity_curve' => []];
    }

    private function execute(array &$portfolio, array $row, string $prediction): bool
    {
        if ($prediction !== 'buy') {
            return false;
        }
        if ($row['entry_at_ms'] < $portfolio['busy_until_ms']) {
            $portfolio['overlapping_buy_signals_skipped']++;

            return false;
        }
        $portfolio['equity'] *= 1 + $row['buy_net_return'];
        if (! is_finite($portfolio['equity'])) {
            throw new RuntimeException('Portfolio return overflow.');
        }
        $portfolio['peak'] = max($portfolio['peak'], $portfolio['equity']);
        $portfolio['max_drawdown'] = max($portfolio['max_drawdown'], 1 - $portfolio['equity'] / $portfolio['peak']);
        $portfolio['trades']++;
        $portfolio['wins'] += $row['buy_net_return'] > 0 ? 1 : 0;
        $portfolio['busy_until_ms'] = $row['label_available_at_ms'];
        $portfolio['equity_curve'][] = ['at_ms' => $row['label_available_at_ms'], 'equity' => $portfolio['equity']];

        return true;
    }

    private function finish(array $portfolio): array
    {
        $portfolio['net_return'] = $portfolio['equity'] - 1;
        $portfolio['win_rate'] = $portfolio['trades'] === 0 ? null : $portfolio['wins'] / $portfolio['trades'];
        unset($portfolio['peak'], $portfolio['busy_until_ms']);

        return $portfolio;
    }
}
