<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleTimeframe;
use App\Repositories\TickerRepository;
use InvalidArgumentException;

final class ActionLabelAnalysis
{
    public const DEFAULT_MIN_DISTANCE_OBSERVATIONS = 30;

    public function __construct(
        private TickerRepository $tickers,
        private ActionAutoLabeler $labels,
        private PublishedTakerFee $takerFees,
        private CandleTimeframe $timeframe,
    ) {}

    public function analyze(string $exchange, string $symbol, string $period, int $asOfMs): array
    {
        $fromMs = KnowledgeWindow::fromMs($asOfMs);
        $fee = $this->takerFees->for($exchange, $symbol);
        if ($fee === null) {
            throw new InvalidArgumentException('Published taker fee is unavailable for Action auto-labeling.');
        }

        $actionLabels = [];
        $actionAvailability = [];
        $actionCounts = ['buy' => 0, 'hold' => 0, 'sell' => 0];
        $frequencies = [];
        $distances = [];
        $pivotCount = 0;
        $candles = 0;
        $gaps = 0;
        $runs = 0;
        $previous = null;
        $run = [];

        $consume = function () use (
            &$run, &$runs, &$actionLabels, &$actionAvailability, &$actionCounts,
            &$frequencies, &$distances, &$pivotCount, $fee, $period
        ): void {
            if ($run === []) {
                return;
            }
            $runs++;
            $summary = $this->summarizeRun($run, $fee, $period);
            $actionLabels += $summary['action_labels'];
            $actionAvailability += $summary['action_available_at_ms'];
            foreach ($actionCounts as $action => $_) {
                $actionCounts[$action] += $summary['action_counts'][$action];
            }
            foreach ($summary['distance_frequencies'] as $distance => $frequency) {
                $frequencies[$distance] = ($frequencies[$distance] ?? 0) + $frequency;
            }
            array_push($distances, ...$summary['distances']);
            $pivotCount += $summary['pivot_count'];
            $run = [];
        };

        foreach ($this->tickers->streamHistory($exchange, $symbol, $period, $fromMs, $asOfMs) as $timestamp => $candle) {
            $timestamp = (int) $timestamp;
            if ($previous !== null && $this->timeframe->next($previous, $period) !== $timestamp) {
                $consume();
                $gaps++;
            }
            $run[] = $this->labels->compact($candle, $timestamp);
            $previous = $timestamp;
            $candles++;
        }
        $consume();

        ksort($frequencies, SORT_NUMERIC);
        sort($distances, SORT_NUMERIC);
        $observations = count($distances);
        $mean = $observations > 0 ? array_sum($distances) / $observations : null;
        $median = null;
        if ($observations > 0) {
            $middle = intdiv($observations, 2);
            $median = $observations % 2
                ? (float) $distances[$middle]
                : ($distances[$middle - 1] + $distances[$middle]) / 2;
        }
        $minimum = max(1, (int) config(
            'intelligence.min_horizon_distance_observations',
            self::DEFAULT_MIN_DISTANCE_OBSERVATIONS
        ));
        $horizon = $observations >= $minimum
            ? max(1, (int) round($mean, 0, PHP_ROUND_HALF_UP))
            : null;

        return [
            'status' => $horizon === null ? 'insufficient_distance_observations' : 'validated',
            'minimum_distance_observations' => $minimum,
            'distance_observations' => $observations,
            'distance_frequencies' => $frequencies,
            'distance_min' => $observations ? min($distances) : null,
            'distance_max' => $observations ? max($distances) : null,
            'distance_mean' => $mean,
            'distance_median' => $median,
            'horizon' => $horizon,
            'formula' => 'H=round(sum(d*freq)/sum(freq))',
            'candles_examined' => $candles,
            'contiguous_runs' => $runs,
            'gaps' => $gaps,
            'pivot_count' => $pivotCount,
            'action_counts' => $actionCounts,
            'from_ms' => $fromMs,
            'as_of_ms' => $asOfMs,
            'max_history_days' => KnowledgeWindow::days(),
            'taker_fee' => $fee,
            '_action_labels' => $actionLabels,
            '_action_available_at_ms' => $actionAvailability,
        ];
    }

    public static function publicDiagnostics(array $analysis): array
    {
        unset($analysis['_action_labels'], $analysis['_action_available_at_ms']);

        return $analysis;
    }

    private function summarizeRun(array $rawRun, float $fee, string $period): array
    {
        $labelled = $this->labels->labels($rawRun, $fee);
        $actionLabels = [];
        $actionAvailability = [];
        $actionCounts = ['buy' => 0, 'hold' => 0, 'sell' => 0];
        $pivots = [];

        foreach ($labelled as $index => $ticker) {
            $action = $ticker['action'] ?? null;
            $timestamp = $ticker['microtimestamp'] ?? null;
            if (is_int($timestamp) && in_array($action, ['buy', 'hold', 'sell'], true)) {
                $actionLabels[$timestamp] = $action === 'hold' ? 'hodl' : $action;
                $actionCounts[$action]++;
            }
            if (is_int($timestamp) && in_array($action, ['buy', 'sell'], true)) {
                $pivots[] = ['index' => $index, 'timestamp' => $timestamp, 'action' => $action];
            }
        }

        $frequencies = [];
        $distances = [];
        for ($i = 0, $last = count($pivots) - 1; $i < $last; $i++) {
            $left = $pivots[$i];
            $right = $pivots[$i + 1];
            if ($left['action'] === $right['action']) {
                throw new InvalidArgumentException(
                    'Finalized Action pivots must alternate before deriving Outcome horizon.'
                );
            }
            $distance = $right['index'] - $left['index'];
            if ($distance < 1) {
                continue;
            }
            $distances[] = $distance;
            $frequencies[$distance] = ($frequencies[$distance] ?? 0) + 1;

            // Retrospective Action labels inside this completed swing become
            // available only when the opposite pivot candle has closed.
            $availableAt = $this->timeframe->next($right['timestamp'], $period);
            for ($j = $left['index']; $j < $right['index']; $j++) {
                $timestamp = $labelled[$j]['microtimestamp'] ?? null;
                if (is_int($timestamp) && isset($actionLabels[$timestamp])) {
                    $actionAvailability[$timestamp] = $availableAt;
                }
            }
        }

        return [
            'action_labels' => $actionLabels,
            'action_available_at_ms' => $actionAvailability,
            'action_counts' => $actionCounts,
            'distance_frequencies' => $frequencies,
            'distances' => $distances,
            'pivot_count' => count($pivots),
        ];
    }
}
