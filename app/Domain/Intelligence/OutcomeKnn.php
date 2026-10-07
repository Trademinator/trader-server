<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\SemanticLabels;
use InvalidArgumentException;

final class OutcomeKnn
{
    public function __construct(private array $settings)
    {
    }

    public function vote(array $neighbors, int $k, array $classWeights = []): array
    {
        foreach ($classWeights as $label => $weight) {
            if (! in_array($label, SemanticLabels::OUTCOMES, true) || ! is_numeric($weight)
                || ! is_finite((float) $weight) || $weight < 0) {
                throw new InvalidArgumentException('Invalid Outcome KNN class weight.');
            }
        }
        $neighbors = array_slice($neighbors, 0, $k);
        if ($neighbors === []) {
            return self::abstain('no_similar_history');
        }
        if ($neighbors[0]['distance'] <= 1e-12) {
            $neighbors = array_values(array_filter($neighbors, fn (array $row): bool => $row['distance'] <= 1e-12));
        }

        $votes = array_fill_keys(SemanticLabels::OUTCOMES, 0.0);
        $weightSum = $squaredSum = $similaritySum = 0.0;
        foreach ($neighbors as $neighbor) {
            if (! array_key_exists($neighbor['label'], $votes)) {
                throw new InvalidArgumentException('Unknown Outcome knowledge label.');
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
        $votes = array_map(fn (float $vote): float => $vote / $weightSum, $votes);
        arsort($votes);
        $ordered = array_values($votes);
        $similarity = $similaritySum / $weightSum;
        $evidence = ['neighbors' => count($neighbors), 'effective_neighbors' => $effective,
            'similarity' => $similarity, 'votes' => $votes];

        if ($effective + 1e-9 < $this->settings['min_effective_neighbors']) {
            return array_replace(self::abstain('insufficient_effective_neighbors'), $evidence);
        }
        if (abs($ordered[0] - $ordered[1]) < 1e-12) {
            return array_replace(self::abstain('tied_votes'), $evidence);
        }
        $confidence = $ordered[0] * $similarity;
        if ($confidence < $this->settings['min_confidence']) {
            return array_replace(self::abstain('weak_consensus'), $evidence);
        }

        return ['outcome' => array_key_first($votes), 'confidence' => $confidence,
            'reason' => 'supported', ...$evidence];
    }

    public static function abstain(string $reason): array
    {
        return ['outcome' => 'neutral', 'confidence' => 0.0, 'reason' => $reason,
            'neighbors' => 0, 'effective_neighbors' => 0.0, 'similarity' => 0.0,
            'votes' => array_fill_keys(SemanticLabels::OUTCOMES, 0.0)];
    }
}
