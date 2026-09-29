<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;
use SplPriorityQueue;

/** Bounded top-K search with normalized RMS distance and inverse-distance voting. */
final class WeightedKnn
{
    public function __construct(
        public readonly float $maxDistance = 0.25,
        public readonly float $minEffective = 3,
        public readonly float $minConfidence = 0.6,
    ) {
        if (! is_finite($maxDistance) || $maxDistance <= 0 || $maxDistance > 1
            || ! is_finite($minEffective) || $minEffective < 1
            || ! is_finite($minConfidence) || $minConfidence < 0.5 || $minConfidence > 1) {
            throw new InvalidArgumentException('Invalid KNN evidence thresholds.');
        }
    }

    public function neighbors(array $rows, array $vector, int $k, int $asOfMs): array
    {
        if ($k < 1 || $vector === []) {
            throw new InvalidArgumentException('K and feature count must be positive.');
        }
        $heap = new SplPriorityQueue;
        $heap->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        foreach ($rows as $index => $row) {
            if ($row['decision_at_ms'] >= $asOfMs || $row['label_available_at_ms'] >= $asOfMs) {
                continue;
            }
            if (count($row['vector']) !== count($vector)) {
                throw new InvalidArgumentException('Knowledge feature dimensions do not match.');
            }
            $sum = 0.0;
            foreach ($vector as $i => $value) {
                $other = $row['vector'][$i];
                if (! is_numeric($value) || ! is_numeric($other) || ! is_finite((float) $value)
                    || ! is_finite((float) $other) || min($value, $other) < 0 || max($value, $other) > 1) {
                    throw new InvalidArgumentException('KNN expects finite unit-interval vectors.');
                }
                $sum += ($value - $other) ** 2;
            }
            $distance = sqrt($sum / count($vector));
            if ($distance > $this->maxDistance) {
                continue;
            }
            $heap->insert(['distance' => $distance, 'label' => $row['label'],
                'decision_at_ms' => $row['decision_at_ms']], [$distance, $index]);
            if ($heap->count() > $k) {
                $heap->extract();
            }
        }
        $neighbors = [];
        foreach ($heap as $entry) {
            $neighbors[] = $entry['data'];
        }

        return array_reverse($neighbors);
    }

    public function vote(array $neighbors, int $k): array
    {
        $neighbors = array_slice($neighbors, 0, $k);
        if ($neighbors === []) {
            return self::abstain('no_similar_history');
        }
        // Exact matches carry all evidence. Distant rows must not inflate their effective count.
        if ($neighbors[0]['distance'] <= 1e-12) {
            $neighbors = array_values(array_filter($neighbors, fn (array $row): bool => $row['distance'] <= 1e-12));
        }
        $votes = ['buy' => 0.0, 'hodl' => 0.0, 'sell' => 0.0];
        $weightSum = $squaredSum = $similaritySum = 0.0;
        foreach ($neighbors as $neighbor) {
            if (! array_key_exists($neighbor['label'], $votes)) {
                throw new InvalidArgumentException('Unknown knowledge label.');
            }
            $weight = 1 / max(1e-9, $neighbor['distance']);
            $votes[$neighbor['label']] += $weight;
            $weightSum += $weight;
            $squaredSum += $weight ** 2;
            $similaritySum += $weight * (1 - $neighbor['distance']);
        }
        $effective = $weightSum ** 2 / $squaredSum;
        $probabilities = array_map(fn (float $vote): float => $vote / $weightSum, $votes);
        arsort($probabilities);
        $ordered = array_values($probabilities);
        $evidence = ['neighbors' => count($neighbors), 'effective_neighbors' => $effective,
            'similarity' => $similaritySum / $weightSum, 'votes' => $probabilities];
        if ($effective + 1e-9 < $this->minEffective) {
            return array_replace(self::abstain('insufficient_effective_neighbors'), $evidence);
        }
        if (abs($ordered[0] - $ordered[1]) < 1e-12) {
            return array_replace(self::abstain('tied_votes'), $evidence);
        }
        $confidence = $ordered[0] * $evidence['similarity'];
        if ($confidence < $this->minConfidence) {
            return array_replace(self::abstain('weak_consensus'), $evidence);
        }

        return ['action' => array_key_first($probabilities), 'confidence' => $confidence,
            'reason' => 'supported', ...$evidence];
    }

    public function predict(array $rows, array $vector, int $k, int $asOfMs): array
    {
        return $this->vote($this->neighbors($rows, $vector, $k, $asOfMs), $k);
    }

    public static function abstain(string $reason): array
    {
        return ['action' => 'hodl', 'confidence' => 0.0, 'reason' => $reason,
            'neighbors' => 0, 'effective_neighbors' => 0.0, 'similarity' => 0.0,
            'votes' => ['buy' => 0.0, 'hodl' => 0.0, 'sell' => 0.0]];
    }
}
