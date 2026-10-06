<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleProvenance;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\FeatureSchema;
use App\Helpers\Decimal;
use App\Models\MarketFeature;
use App\Repositories\TickerRepository;
use InvalidArgumentException;
use RuntimeException;

/** Reuse an action, never its obsolete feature vector or a changed reviewed chart. */
final class HumanCandleProjection
{
    public const VERSION = 'human-candle-current-features-v1';

    public function __construct(private TickerRepository $tickers, private CandleTimeframe $timeframe) {}

    /**
     * Inputs have already been authenticated against their original frozen dataset.
     * This operation does not create snapshots, relabel candles, or publish a model.
     *
     * @param  array<string, array>  $payloads  verified snapshots keyed by snapshot ID
     * @return array<string, array> projected vector/provenance or exclusion reason
     */
    public function project(array $manifest, array $payloads, array $keys, float $deadline): array
    {
        if ($manifest['feature_version'] !== FeatureEngine::VERSION) {
            throw new InvalidArgumentException('Human candle projection requires the current feature version.');
        }
        if ($payloads === []) {
            return [];
        }
        $period = $manifest['period'];
        $times = array_values(array_unique(array_column($payloads, 'microtimestamp')));
        $features = MarketFeature::query()->where('exchange', $manifest['exchange'])->where('symbol', $manifest['symbol'])
            ->where('period', $period)->where('version', $manifest['feature_version'])->whereIn('microtimestamp', $times)
            ->get()->keyBy('microtimestamp');
        $result = $requested = [];
        foreach ($payloads as $id => $payload) {
            $this->deadline($deadline);
            $timestamp = $payload['microtimestamp'];
            $decision = $payload['decision_at_ms'];
            $record = $features->get($timestamp);
            if ($record === null) {
                $result[$id] = ['reason' => 'missing_current_features'];

                continue;
            }
            $current = $record->payload;
            if ($decision !== $this->timeframe->next($timestamp, $period)
                || ($current['version'] ?? null) !== $manifest['feature_version']
                || ($current['microtimestamp'] ?? null) !== $timestamp
                || ($current['available_at_ms'] ?? null) !== $decision || $record->available_at_ms !== $decision) {
                $result[$id] = ['reason' => 'current_feature_time_mismatch'];

                continue;
            }
            if (($current['source_available_at_ms'] ?? $decision) > $decision) {
                $result[$id] = ['reason' => 'unavailable_evidence'];

                continue;
            }
            $vector = FeatureSchema::vector($current, $keys);
            if ($vector === null || ($current['technical_ready'] ?? true) === false) {
                $result[$id] = ['reason' => 'missing_current_features'];

                continue;
            }
            $series = $payload['series'] ?? [];
            if (! is_array($series) || ! array_is_list($series) || count($series) < 2
                || ($series[array_key_last($series)]['time'] ?? null) !== intdiv($timestamp, 1000)) {
                $result[$id] = ['reason' => 'missing_reviewed_chart'];

                continue;
            }
            // FeatureEngine stores close as a float; preserve that close contract.
            // The chart identity below still compares exact decimal values.
            if (! isset($current['close']) || (float) $current['close'] !== (float) $series[array_key_last($series)]['close']) {
                $result[$id] = ['reason' => 'current_feature_candle_mismatch'];

                continue;
            }
            $signatures = [];
            $previous = null;
            foreach ($series as $bar) {
                $time = $bar['time'] ?? null;
                if (! is_int($time) || ($previous !== null && $time <= $previous)) {
                    throw new InvalidArgumentException('Invalid human candle chart chronology.');
                }
                $signatures[] = $this->signature($bar);
                $previous = $time;
            }
            // Preserve the original chart extent when configuration shrinks; also
            // detect newly filled leading bars inside the configured chart window.
            $from = $timestamp;
            for ($i = 1; $i < config('human_training.chart_candles'); $i++) {
                $from = max(0, $this->timeframe->previous($from, $period));
            }
            $from = min($from, $series[0]['time'] * 1000);
            $requested[$id] = ['from' => $from, 'to' => $timestamp, 'signatures' => $signatures,
                'decision' => $decision, 'vector' => NormalizedVector::from($vector, $keys),
                'provenance' => ['projection_version' => self::VERSION,
                    'feature_id' => $record->getKey(), 'feature_version' => $manifest['feature_version'],
                    'feature_sha256' => hash('sha256', json_encode($current, JSON_THROW_ON_ERROR)),
                    'chart_sha256' => hash('sha256', json_encode($signatures, JSON_THROW_ON_ERROR))]];
        }
        if ($requested === []) {
            return $result;
        }
        // One bounded canonical-history read per annotation batch, never an N+1
        // chart replay. DatasetRows keeps the large source artifact on disk.
        $historyTimes = $historySignatures = $available = [];
        foreach ($this->tickers->streamHistory($manifest['exchange'], $manifest['symbol'], $period,
            min(array_column($requested, 'from')), max(array_column($requested, 'to'))) as $bar) {
            $this->deadline($deadline);
            $timestamp = (int) $bar['microtimestamp'];
            $historyTimes[] = $timestamp;
            $historySignatures[] = $this->signature([...$bar, 'time' => intdiv($timestamp, 1000)]);
            $available[] = CandleProvenance::availableAt($bar, $period);
        }
        foreach ($requested as $id => $request) {
            $this->deadline($deadline);
            $start = $this->lowerBound($historyTimes, $request['from']);
            $end = $this->lowerBound($historyTimes, $request['to'] + 1);
            if (array_slice($historySignatures, $start, $end - $start) !== $request['signatures']) {
                $result[$id] = ['reason' => 'reviewed_chart_changed'];
            } elseif (max(array_slice($available, $start, $end - $start)) > $request['decision']) {
                $result[$id] = ['reason' => 'unavailable_evidence'];
            } else {
                $result[$id] = ['vector' => $request['vector'], 'provenance' => $request['provenance']];
            }
        }

        return $result;
    }

    /** Exact decimal identity; formatting-only changes are not market changes. */
    private function signature(array $bar): string
    {
        $values = [(int) $bar['time']];
        foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
            $number = $bar[$field] ?? null;
            if ((! is_string($number) && ! is_int($number) && ! is_float($number)) || ! is_numeric($number)) {
                throw new InvalidArgumentException('Invalid human candle chart value.');
            }
            $value = Decimal::normalize($number);
            if (str_contains($value, '.')) {
                $value = rtrim(rtrim($value, '0'), '.');
            }
            $values[] = in_array($value, ['', '-0', '+0'], true) ? '0' : $value;
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function lowerBound(array $times, int $wanted): int
    {
        $low = 0;
        $high = count($times);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($times[$mid] < $wanted) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    private function deadline(float $deadline): void
    {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Candle guidance training time budget exceeded.');
        }
    }
}
