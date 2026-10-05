<?php

namespace App\Domain\MarketData;

use App\Domain\Operations\ActionLog;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use InvalidArgumentException;

use function Trademinator\BcMath\bcdec;

/** Recover isolated holes, keeping evidence-based reconstruction and inferred filling distinct. */
final class CandleReconstructor
{
    public function __construct(private TickerRepository $tickers, private ClosedCandleAggregator $aggregator, private ActionLog $log) {}

    public function reconstruct(ExchangeRepository $exchanges, string $exchange, string $symbol,
        string $period, int $at, int $cutoffMs): ?array
    {
        return $this->attempt($exchanges, $exchange, $symbol, $period, $at, $cutoffMs)['candle'];
    }

    /** @return array{candle: ?array, diagnostics: array{candle_ms: int, reason: string, checks: array}} */
    public function attempt(ExchangeRepository $exchanges, string $exchange, string $symbol,
        string $period, int $at, int $cutoffMs): array
    {
        $result = $this->recover($exchanges, $exchange, $symbol, $period, $at, $cutoffMs);
        $fields = ['exchange' => $exchange, 'symbol' => $symbol, 'period' => $period, 'candle_ms' => $at];
        foreach ($result['checks'] as $check) {
            $this->log->write('candles.reconstruction_check', [...$fields, 'period' => $check['period'],
                'source' => $check['stage'], 'reason' => $check['reason'], 'rows' => $check['rows'] ?? 0,
                'outcome' => 'completed']);
        }
        $this->log->write('candles.reconstruction', [...$fields, 'reason' => $result['reason'],
            'outcome' => $result['candle'] === null ? 'skipped' : 'completed']);

        return ['candle' => $result['candle'], 'diagnostics' => ['candle_ms' => $at,
            'reason' => $result['reason'], 'checks' => $result['checks']]];
    }

    /** @return array{candle: ?array, reason: string, checks: array} */
    private function recover(ExchangeRepository $exchanges, string $exchange, string $symbol,
        string $period, int $at, int $cutoffMs): array
    {
        $checks = [];
        if (! in_array($period, CandleTimeframe::SUPPORTED, true) || ! preg_match('/^\d+[mhd]$/D', $period)) {
            return $this->result($checks, 'unsupported_period');
        }
        $step = $this->aggregator->duration($period);
        if ($at < $step || $at % $step !== 0 || $at + 2 * $step > $cutoffMs) {
            return $this->result($checks, 'unaligned_or_unclosed_neighbors');
        }
        $neighbors = iterator_to_array($this->tickers->streamHistory($exchange, $symbol, $period, $at - $step, $at + $step));
        if (isset($neighbors[$at])) {
            return $this->result($checks, 'already_present');
        }
        if (! isset($neighbors[$at - $step], $neighbors[$at + $step])) {
            return $this->result($checks, 'not_isolated');
        }
        foreach ([$neighbors[$at - $step], $neighbors[$at + $step]] as $neighbor) {
            if (isset($neighbor['reconstruction']) || isset($neighbor['derived_from'])) {
                return $this->result($checks, 'reconstructed_neighbor');
            }
        }
        $periods = $exchanges->periods();
        $deadline = microtime(true) + 45;
        $lower = [];
        $activityObserved = false;
        $lowerPeriods = $this->candidatePeriods($periods, $step, true);
        foreach ($lowerPeriods as $base => $baseStep) {
            if (microtime(true) >= $deadline) {
                return $this->result($checks, 'evidence_budget_exhausted');
            }
            $rows = $this->rows($exchanges->fetchCandleEvidence($symbol, $base, $at, $at + $step, intdiv($step, $baseStep)),
                $at, $at + $step, $baseStep);
            $complete = count($rows) === intdiv($step, $baseStep);
            $checks[] = ['stage' => 'lower_timeframe', 'period' => $base, 'rows' => count($rows),
                'expected' => intdiv($step, $baseStep), 'reason' => $complete ? 'complete' : 'incomplete'];
            foreach ($rows as $row) {
                $activityObserved = $activityObserved || $this->compare($row['volume'], '0') > 0;
            }
            if ($complete) {
                $bar = $this->aggregate($rows, $at);

                return $this->result($checks, 'reconstructed_lower_timeframe',
                    $this->annotate($bar, 'lower_timeframe', $base, $at, $at + $step, array_values($rows), 'complete_lower_timeframe'));
            }
            if ($rows !== []) {
                $lower[$base] = $rows;
            }
        }

        if ($lowerPeriods !== [] && $lower === []) {
            $next = $neighbors[$at + $step];
            $price = $next['open'] ?? null;
            if (! is_numeric($price) || $this->compare((string) $price, '0') <= 0) {
                throw new InvalidArgumentException('Invalid next open for candle reconstruction.');
            }
            $bar = ['microtimestamp' => $at, 'open' => $price, 'high' => $price,
                'low' => $price, 'close' => $price, 'volume' => '0'];
            $evidence = ['lower_checks' => $checks, 'next' => $next, 'previous' => $neighbors[$at - $step]];
            $bar = $this->annotate($bar, 'next_open', $period, $at + $step, $at + 2 * $step,
                $evidence, 'empty_lower_timeframes', CandleProvenance::availableAt($next, $period));
            $bar['reconstruction']['inferred'] = true;
            $checks[] = ['stage' => 'empty_interval', 'period' => $period, 'reason' => 'inferred_from_next_open'];

            return $this->result($checks, 'reconstructed_next_open', $bar);
        }

        $reason = 'no_supported_parent_timeframe';
        foreach ($this->candidatePeriods($periods, $step, false) as $parentPeriod => $parentStep) {
            $start = intdiv($at, $parentStep) * $parentStep;
            $end = $start + $parentStep;
            $check = ['stage' => 'parent', 'period' => $parentPeriod];
            if ($end > $cutoffMs) {
                $checks[] = [...$check, 'reason' => $reason = 'parent_not_closed'];

                continue;
            }
            if (microtime(true) >= $deadline) {
                return $this->result($checks, 'evidence_budget_exhausted');
            }
            $parents = $this->rows($exchanges->fetchCandleEvidence($symbol, $parentPeriod, $start, $end, 1), $start, $end, $parentStep);
            $parent = $parents[$start] ?? null;
            if ($parent === null || ! $this->validTradeMetadata($parent, $parentStep)) {
                $checks[] = [...$check, 'rows' => count($parents),
                    'reason' => $reason = $parent === null ? 'parent_candle_unavailable' : 'invalid_trade_metadata'];

                continue;
            }
            foreach (['first_trade_time', 'last_trade_time'] as $tradeTime) {
                if (isset($parent[$tradeTime]) && $parent[$tradeTime] >= $at && $parent[$tradeTime] < $at + $step && ! $activityObserved) {
                    $checks[] = [...$check, 'reason' => 'parent_reports_uncovered_trades'];

                    return $this->result($checks, 'parent_reports_uncovered_trades');
                }
            }
            if (microtime(true) >= $deadline) {
                return $this->result($checks, 'evidence_budget_exhausted');
            }
            $siblings = $this->rows($exchanges->fetchCandleEvidence($symbol, $period, $start, $end, intdiv($parentStep, $step)),
                $start, $end, $step);
            if (isset($siblings[$at])) {
                $checks[] = [...$check, 'reason' => 'native_candle_recovered'];

                return $this->result($checks, 'native_candle_recovered', $siblings[$at]);
            }
            if (count($siblings) !== intdiv($parentStep, $step) - 1) {
                $checks[] = [...$check, 'rows' => count($siblings), 'expected' => intdiv($parentStep, $step) - 1,
                    'reason' => $reason = 'parent_siblings_incomplete'];

                continue;
            }
            if (array_any($siblings, fn ($row) => ! $this->validTradeMetadata($row, $step))) {
                $checks[] = [...$check, 'reason' => $reason = 'invalid_trade_metadata'];

                continue;
            }
            $candidates = $lower;
            if (! $activityObserved) {
                $candidates[$period] = [];
            }
            foreach ($candidates as $base => $rows) {
                if (array_any($rows, fn ($row) => ! $this->validTradeMetadata($row, $this->aggregator->duration($base)))) {
                    $checks[] = [...$check, 'source_period' => $base, 'reason' => $reason = 'invalid_trade_metadata'];

                    continue;
                }
                $combined = $siblings + $rows;
                ksort($combined, SORT_NUMERIC);
                $failure = $this->reconcile($parent, $combined);
                $checks[] = [...$check, 'source_period' => $base, 'rows' => count($combined),
                    'reason' => $failure ?? 'reconciled'];
                if ($failure !== null) {
                    $reason = $failure;

                    continue;
                }
                $empty = $rows === [] || $this->compare($this->aggregate($rows, $at)['volume'], '0') === 0;
                if ($empty && $activityObserved) {
                    $reason = 'conflicting_lower_evidence';

                    continue;
                }
                $price = $neighbors[$at - $step]['close'];
                if (! is_numeric($price) || $this->compare((string) $price, '0') <= 0) {
                    throw new InvalidArgumentException('Invalid prior close for candle reconstruction.');
                }
                $bar = $empty ? ['microtimestamp' => $at, 'open' => $price, 'high' => $price,
                    'low' => $price, 'close' => $price, 'volume' => '0'] : $this->aggregate($rows, $at);
                if ($empty && $this->compare($parent['volume'], '0') === 0
                    && array_any(['open', 'high', 'low', 'close'], fn ($key) => $this->compare($parent[$key], $price) !== 0)) {
                    return $this->result($checks, 'empty_parent_price_mismatch');
                }
                foreach ($lower as $observed) {
                    $known = $this->aggregate($observed, $at);
                    if ($this->compare($known['volume'], $bar['volume']) > 0
                        || ($this->compare($known['volume'], '0') > 0
                            && ($this->compare($known['high'], $bar['high']) > 0 || $this->compare($known['low'], $bar['low']) < 0))) {
                        return $this->result($checks, 'conflicting_lower_evidence');
                    }
                }
                $method = $empty ? 'no_trades' : 'lower_timeframe';
                $verification = array_key_exists('trade_count', $parent) ? 'parent_ohlcv_and_trade_counts' : 'parent_ohlcv';
                $evidence = ['parent_period' => $parentPeriod, 'parent' => $parent, 'siblings' => array_values($siblings),
                    'lower_period' => $base, 'lower' => array_values($rows), 'previous' => $neighbors[$at - $step]];

                return $this->result($checks, 'reconstructed_'.$method,
                    $this->annotate($bar, $method, $empty ? $parentPeriod : $base, $empty ? $start : $at,
                        $empty ? $end : $at + $step, $evidence, $verification, $end));
            }

            // A complete, contradictory parent must not be overridden by a larger window.
            return $this->result($checks, $reason);
        }

        return $this->result($checks, $reason);
    }

    /** @return array<string, int> */
    private function candidatePeriods(array $periods, int $step, bool $lower): array
    {
        $candidates = [];
        foreach (array_keys($periods) as $period) {
            if (! in_array($period, CandleTimeframe::SUPPORTED, true) || ! preg_match('/^\d+[mhd]$/D', $period)) {
                continue;
            }
            $duration = $this->aggregator->duration($period);
            if ($lower ? ($duration < $step && $step % $duration === 0 && intdiv($step, $duration) <= 100)
                : ($duration > $step && $duration % $step === 0 && intdiv($duration, $step) <= 100)) {
                $candidates[$period] = $duration;
            }
        }
        $lower ? arsort($candidates, SORT_NUMERIC) : asort($candidates, SORT_NUMERIC);

        return array_slice($candidates, 0, $lower ? 3 : 2, true);
    }

    /** @return array<int, array> */
    private function rows(array $rows, int $from, int $until, int $step): array
    {
        $result = [];
        foreach ($rows as $row) {
            $timestamp = $row['microtimestamp'] ?? null;
            if (! is_int($timestamp)) {
                throw new InvalidArgumentException('Invalid reconstruction evidence timestamp.');
            }
            if ($timestamp < $from || $timestamp >= $until) {
                continue;
            }
            if ($timestamp % $step !== 0 || isset($result[$timestamp]) || isset($row['reconstruction']) || isset($row['derived_from'])) {
                throw new InvalidArgumentException('Reconstruction requires unique aligned exchange candles.');
            }
            if (! is_numeric($row['volume'] ?? null)) {
                throw new InvalidArgumentException('Candle reconstruction evidence requires an explicit volume.');
            }
            $normalized = (new OhlcvNormalizer)->normalize([$row]);
            $result[$timestamp] = $normalized[0];
            if ($this->compare($result[$timestamp]['low'], '0') <= 0) {
                throw new InvalidArgumentException('Invalid reconstruction evidence price.');
            }
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /** Missing child slots are allowed here only when the complete parent accounts for their activity. */
    private function aggregate(array $rows, int $at): array
    {
        $traded = array_filter($rows, fn ($row) => $this->compare($row['volume'], '0') > 0);
        $prices = $traded === [] ? $rows : $traded;
        $first = reset($prices);
        $last = end($prices);
        $bar = ['microtimestamp' => $at, 'open' => $first['open'], 'high' => $first['high'], 'low' => $first['low'],
            'close' => $last['close'], 'volume' => '0'];
        foreach ($prices as $row) {
            $bar['high'] = $this->compare($row['high'], $bar['high']) > 0 ? $row['high'] : $bar['high'];
            $bar['low'] = $this->compare($row['low'], $bar['low']) < 0 ? $row['low'] : $bar['low'];
        }
        foreach ($rows as $row) {
            $bar['volume'] = bcadd($bar['volume'], $row['volume'], max(2, bcdec([$bar['volume'], $row['volume']])));
        }

        return $bar;
    }

    private function validTradeMetadata(array $row, int $step): bool
    {
        $empty = $this->compare($row['volume'], '0') === 0;
        if (array_key_exists('trade_count', $row)
            && (! is_int($row['trade_count']) || $row['trade_count'] < 0 || ($row['trade_count'] === 0) !== $empty)) {
            return false;
        }
        if (array_key_exists('first_trade_time', $row) || array_key_exists('last_trade_time', $row)) {
            if ($empty) {
                return ($row['first_trade_time'] ?? null) === null && ($row['last_trade_time'] ?? null) === null;
            }

            return is_int($row['first_trade_time'] ?? null) && is_int($row['last_trade_time'] ?? null)
                && $row['first_trade_time'] >= $row['microtimestamp'] && $row['first_trade_time'] <= $row['last_trade_time']
                && $row['last_trade_time'] < $row['microtimestamp'] + $step;
        }

        return true;
    }

    private function reconcile(array $parent, array $rows): ?string
    {
        $aggregate = $this->aggregate($rows, $parent['microtimestamp']);
        if ($this->compare($aggregate['volume'], $parent['volume']) !== 0) {
            return 'parent_volume_mismatch';
        }
        foreach (['open', 'high', 'low', 'close'] as $key) {
            if ($this->compare($aggregate[$key], $parent[$key]) !== 0) {
                return 'parent_prices_mismatch';
            }
        }
        if (array_key_exists('trade_count', $parent)) {
            if (array_any($rows, fn ($row) => ! is_int($row['trade_count'] ?? null))) {
                return 'trade_counts_incomplete';
            }
            if (array_sum(array_column($rows, 'trade_count')) !== $parent['trade_count']) {
                return 'parent_trade_count_mismatch';
            }
        }
        $traded = array_filter($rows, fn ($row) => $this->compare($row['volume'], '0') > 0);
        if ($traded !== [] && (isset($parent['first_trade_time']) || isset($parent['last_trade_time']))) {
            if (array_any($traded, fn ($row) => ! is_int($row['first_trade_time'] ?? null) || ! is_int($row['last_trade_time'] ?? null))) {
                return 'trade_times_incomplete';
            }
            if ($parent['first_trade_time'] !== reset($traded)['first_trade_time'] || $parent['last_trade_time'] !== end($traded)['last_trade_time']) {
                return 'parent_trade_times_mismatch';
            }
        }

        return null;
    }

    private function compare(string $left, string $right): int
    {
        return bccomp($left, $right, max(2, bcdec([$left, $right])));
    }

    private function result(array $checks, string $reason, ?array $candle = null): array
    {
        return ['candle' => $candle, 'reason' => $reason, 'checks' => $checks];
    }

    private function annotate(array $bar, string $method, string $sourcePeriod, int $from, int $until,
        array $evidence, string $verification, ?int $availableAt = null): array
    {
        $bar['reconstruction'] = [
            'version' => CandleProvenance::VERSION, 'method' => $method, 'verification' => $verification,
            'source_period' => $sourcePeriod, 'source_from_ms' => $from, 'source_until_ms' => $until,
            'available_at_ms' => $availableAt ?? $until, 'reconstructed_at_ms' => now()->getTimestampMs(),
            'evidence_sha256' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)), 'evidence' => $evidence,
        ];

        return $bar;
    }
}
