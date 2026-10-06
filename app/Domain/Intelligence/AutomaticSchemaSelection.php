<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\WalkForward;
use App\Models\MarketFeature;
use InvalidArgumentException;
use RuntimeException;

/** Select inputs before fitting; never use target values or validation scores. */
final class AutomaticSchemaSelection
{
    public const VERSION = 'automatic-context-fallback-v1';

    public function __construct(private CandleTimeframe $timeframe) {}

    /**
     * Capacity is an upper bound: source gaps, semantic warmup, reconstruction
     * availability and later pattern/lead-lag exclusions still apply in training.
     * No data, model, queue or cache records are changed by this inspection.
     */
    public function inspect(string $exchange, string $symbol, string $period, int $fromMs, int $toMs,
        int $asOfMs, int $horizon, float $deadline): array
    {
        if ($exchange === '' || $symbol === '' || $fromMs < 0 || $fromMs > $toMs || $toMs > $asOfMs || $horizon < 1) {
            throw new InvalidArgumentException('Invalid automatic schema inspection window.');
        }
        $this->deadline($deadline);
        $this->timeframe->next(0, $period);
        $started = hrtime(true);
        $keys = ['technical' => FeatureSchema::keys('technical'), 'full' => FeatureSchema::keys('full')];
        $complete = ['technical' => 0, 'full' => 0];
        $rows = ['technical' => [], 'full' => []];
        $total = 0;
        $missing = array_fill_keys($keys['full'], 0);
        $query = MarketFeature::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
            ->where('version', FeatureEngine::VERSION)->whereBetween('available_at_ms', [$fromMs, $toMs]);
        foreach ($query->lazyById(500, 'microtimestamp') as $feature) {
            $this->deadline($deadline);
            $payload = $feature->payload;
            $decision = $this->timeframe->next($feature->microtimestamp, $period);
            if (($payload['version'] ?? null) !== FeatureEngine::VERSION
                || ($payload['microtimestamp'] ?? null) !== $feature->microtimestamp
                || ($payload['available_at_ms'] ?? null) !== $decision || $feature->available_at_ms !== $decision) {
                throw new RuntimeException('M2 feature time/version mismatch; rebuild features.');
            }
            $total++;
            foreach ($missing as $key => $count) {
                if (($payload['features'][$key] ?? null) === null) {
                    $missing[$key]++;
                }
            }
            $available = $decision;
            for ($i = 0; $i < $horizon && $available <= $asOfMs; $i++) {
                $available = $this->timeframe->next($available, $period);
            }
            foreach ($keys as $schema => $selected) {
                if (FeatureSchema::vector($payload, $selected) === null) {
                    continue;
                }
                $complete[$schema]++;
                if ($available <= $asOfMs) {
                    $rows[$schema][] = ['decision_at_ms' => $decision, 'label_available_at_ms' => $available];
                }
            }
        }
        $settings = config('intelligence.knn');
        $capacity = [];
        foreach ($rows as $schema => $candidates) {
            $capacity[$schema] = $this->capacity($candidates, $settings, $deadline);
        }
        $this->deadline($deadline);
        $fallback = ! $capacity['full']['sufficient'] && $capacity['technical']['sufficient'];

        return ['version' => self::VERSION, 'requested_schema' => 'full',
            'effective_schema' => $fallback ? 'technical' : 'full', 'fallback_policy' => 'technical',
            'reason' => $fallback ? 'insufficient_full_feature_history'
                : ($capacity['full']['sufficient'] ? 'full_feature_history_sufficient' : 'insufficient_both_feature_histories'),
            'selection_basis' => 'feature_availability_and_chronology_only', 'validation_performed' => false,
            'feature_rows' => $total, 'complete_feature_rows' => $complete,
            'missing_by_key' => $missing, 'potential_history' => $capacity,
            'window' => ['from_ms' => $fromMs, 'to_ms' => $toMs, 'as_of_ms' => $asOfMs],
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000)];
    }

    /** @param list<array{decision_at_ms: int, label_available_at_ms: int}> $rows */
    private function capacity(array $rows, array $settings, float $deadline): array
    {
        $testStart = (int) floor(count($rows) * 0.8);
        $test = array_slice($rows, $testStart);
        $cutoff = $test[0]['decision_at_ms'] ?? 0;
        $training = array_values(array_filter(array_slice($rows, 0, $testStart),
            fn (array $row): bool => $row['label_available_at_ms'] < $cutoff));
        $evaluated = 0;
        foreach ((new WalkForward)->folds($training, $settings['min_train_size'], $settings['test_size'], $settings['gap'], true, null) as $fold) {
            $this->deadline($deadline);
            $evaluated += count($fold['test']);
            if ($evaluated >= $settings['min_validation_rows']) {
                break;
            }
        }

        return ['potential_mature_rows' => count($rows), 'tuning_rows_after_purge' => count($training),
            'holdout_rows' => count($test), 'minimum_training_rows' => $settings['min_train_size'],
            'minimum_validation_rows' => $settings['min_validation_rows'],
            'has_tuning_validation_capacity' => $evaluated >= $settings['min_validation_rows'],
            'sufficient' => count($test) >= $settings['min_validation_rows'] && $evaluated >= $settings['min_validation_rows']];
    }

    private function deadline(float $deadline): void
    {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Automatic schema inspection exceeded the intelligence build budget.');
        }
    }
}
