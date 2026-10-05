<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;
use SplPriorityQueue;

/** Bounded top-K search with normalized RMS distance and inverse-distance voting. */
final class WeightedKnn
{
    private const EARLY_EXIT_EPSILON = 1e-12;

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

    /** Validate and flatten knowledge vectors once before repeated KNN scans. */
    public function prepareRows(array $rows): array
    {
        $dimensions = null;
        foreach ($rows as &$row) {
            if (! isset($row['vector']) || ! is_array($row['vector']) || ! array_is_list($row['vector']) || $row['vector'] === []) {
                throw new InvalidArgumentException('Knowledge vectors must be nonempty lists.');
            }
            $dimensions ??= count($row['vector']);
            if (count($row['vector']) !== $dimensions) {
                throw new InvalidArgumentException('Knowledge feature dimensions do not match.');
            }
            $row['vector'] = $this->prepareVector($row['vector'], $dimensions);
            $rowWeights = $row['feature_weights'] ?? [];
            if (! is_array($rowWeights)) {
                throw new InvalidArgumentException('Knowledge feature weights must be a list.');
            }
            $row['feature_weights'] = $this->prepareWeights($rowWeights, $dimensions, 'Knowledge');
        }
        unset($row);

        return $rows;
    }

    public function neighbors(array $rows, array $vector, int $k, int $asOfMs, array $weights = []): array
    {
        if ($k < 1 || $vector === []) {
            throw new InvalidArgumentException('K and feature count must be positive.');
        }
        $vector = $this->prepareVector($vector);
        $weights = $this->prepareWeights($weights, count($vector), 'Query');

        return $this->neighborsPrepared($this->prepareRows($rows), $vector, $k, $asOfMs, $weights);
    }

    /** Hot path for rows and query vectors already validated as finite unit-interval floats. */
    public function neighborsPrepared(array $rows, array $vector, int $k, int $asOfMs, array $weights = []): array
    {
        if ($k < 1 || $vector === []) {
            throw new InvalidArgumentException('K and feature count must be positive.');
        }
        $dimensions = count($vector);
        if ($weights !== [] && count($weights) !== $dimensions) {
            throw new InvalidArgumentException('Query feature weights do not match the vector.');
        }
        if ($rows !== [] && count($rows[array_key_first($rows)]['vector']) !== $dimensions) {
            throw new InvalidArgumentException('Knowledge feature dimensions do not match.');
        }

        $heap = new SplPriorityQueue;
        $heap->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $cutoff = $this->maxDistance;
        foreach ($rows as $index => $row) {
            if ($row['decision_at_ms'] >= $asOfMs || $row['label_available_at_ms'] >= $asOfMs) {
                continue;
            }
            $rowWeights = $row['feature_weights'] ?? [];
            $distance = $weights === [] && $rowWeights === []
                ? $this->unweightedDistance($vector, $row['vector'], $dimensions, $cutoff)
                : $this->weightedDistance($vector, $row['vector'], $weights, $rowWeights, $dimensions, $cutoff);
            if ($distance === null || $distance > $cutoff || $distance > $this->maxDistance) {
                continue;
            }
            $heap->insert(['distance' => $distance, 'label' => $row['label'],
                'decision_at_ms' => $row['decision_at_ms']], [$distance, $index]);
            if ($heap->count() > $k) {
                $heap->extract();
            }
            if ($heap->count() === $k) {
                $heap->top();
                $worst = $heap->current();
                $cutoff = min($this->maxDistance, (float) $worst['data']['distance']);
            }
        }
        $neighbors = [];
        foreach ($heap as $entry) {
            $neighbors[] = $entry['data'];
        }

        return array_reverse($neighbors);
    }

    public function vote(array $neighbors, int $k, array $classWeights = []): array
    {
        foreach ($classWeights as $label => $weight) {
            if (! in_array($label, ['buy', 'hodl', 'sell'], true) || ! is_numeric($weight)
                || ! is_finite((float) $weight) || $weight < 0) {
                throw new InvalidArgumentException('Invalid KNN class weight.');
            }
        }
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
            $weight = ($classWeights[$neighbor['label']] ?? 1.0) / max(1e-9, $neighbor['distance']);
            $votes[$neighbor['label']] += $weight;
            $weightSum += $weight;
            $squaredSum += $weight ** 2;
            $similaritySum += $weight * (1 - $neighbor['distance']);
        }
        if ($weightSum <= 0) {
            return self::abstain('no_weighted_evidence');
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

    public function predict(array $rows, array $vector, int $k, int $asOfMs, array $weights = []): array
    {
        return $this->vote($this->neighbors($rows, $vector, $k, $asOfMs, $weights), $k);
    }

    public static function abstain(string $reason): array
    {
        return ['action' => 'hodl', 'confidence' => 0.0, 'reason' => $reason,
            'neighbors' => 0, 'effective_neighbors' => 0.0, 'similarity' => 0.0,
            'votes' => ['buy' => 0.0, 'hodl' => 0.0, 'sell' => 0.0]];
    }

    private function prepareVector(array $vector, ?int $dimensions = null): array
    {
        if (! array_is_list($vector) || $vector === [] || ($dimensions !== null && count($vector) !== $dimensions)) {
            throw new InvalidArgumentException('KNN feature dimensions do not match.');
        }
        foreach ($vector as &$value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 1) {
                throw new InvalidArgumentException('KNN expects finite unit-interval vectors.');
            }
            $value = (float) $value;
        }
        unset($value);

        return $vector;
    }

    private function prepareWeights(array $weights, int $dimensions, string $scope): array
    {
        if ($weights === []) {
            return [];
        }
        if (! array_is_list($weights) || count($weights) !== $dimensions) {
            throw new InvalidArgumentException($scope.' feature weights do not match the vector.');
        }
        foreach ($weights as &$weight) {
            if (! is_numeric($weight) || ! is_finite((float) $weight) || $weight < 0 || $weight > 1) {
                throw new InvalidArgumentException('Feature weights must be finite unit-interval values.');
            }
            $weight = (float) $weight;
        }
        unset($weight);

        return $weights;
    }

    private function unweightedDistance(array $vector, array $other, int $dimensions, float $cutoff): ?float
    {
        $sum = 0.0;
        $limit = $cutoff * $cutoff * $dimensions;
        for ($i = 0; $i < $dimensions; $i++) {
            $sum += ($vector[$i] - $other[$i]) ** 2;
            if ($sum > $limit + self::EARLY_EXIT_EPSILON) {
                return null;
            }
        }

        return sqrt($sum / $dimensions);
    }

    private function weightedDistance(array $vector, array $other, array $weights, array $otherWeights,
        int $dimensions, float $cutoff): ?float
    {
        $effectiveDimensions = 0.0;
        for ($i = 0; $i < $dimensions; $i++) {
            $effectiveDimensions += min($weights[$i] ?? 1.0, $otherWeights[$i] ?? 1.0);
        }
        if ($effectiveDimensions <= 0) {
            return null;
        }

        $sum = 0.0;
        $limit = $cutoff * $cutoff * $effectiveDimensions;
        for ($i = 0; $i < $dimensions; $i++) {
            $weight = min($weights[$i] ?? 1.0, $otherWeights[$i] ?? 1.0);
            if ($weight <= 0) {
                continue;
            }
            $sum += $weight * ($vector[$i] - $other[$i]) ** 2;
            if ($sum > $limit + self::EARLY_EXIT_EPSILON) {
                return null;
            }
        }

        return sqrt($sum / $effectiveDimensions);
    }
}
