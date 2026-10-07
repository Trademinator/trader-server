<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;

final class TrainingSourceFusion
{
    public function __construct(private float $minimumConfidence = 0.60)
    {
        if (! is_finite($minimumConfidence) || $minimumConfidence < 0.5 || $minimumConfidence > 1.0) {
            throw new InvalidArgumentException('Invalid training-source confidence threshold.');
        }
    }

    /**
     * Combine algorithmic and human predictions of the same target.
     * Human influence follows W_H=min(.60,.60*sqrt(N_H/750)); a lone supported
     * source receives 100% effective weight.
     */
    public function combine(array $algorithmic, array $human, array $labels, string $resultKey, int $humanSamples): array
    {
        $configuredHuman = HumanTrainingWeight::human($humanSamples);
        $configured = ['algorithmic' => 1.0 - $configuredHuman, 'human' => $configuredHuman];
        $predictions = ['algorithmic' => $algorithmic, 'human' => $human];

        $effective = ['algorithmic' => 0.0, 'human' => 0.0];
        foreach ($predictions as $source => $prediction) {
            if (($prediction['reason'] ?? null) === 'supported') {
                $effective[$source] = $configured[$source];
            }
        }
        $algorithmicSupported = ($algorithmic['reason'] ?? null) === 'supported';
        $humanSupported = ($human['reason'] ?? null) === 'supported';
        if ($algorithmicSupported && ! $humanSupported) {
            $effective = ['algorithmic' => 1.0, 'human' => 0.0];
        } elseif ($humanSupported && ! $algorithmicSupported) {
            $effective = ['algorithmic' => 0.0, 'human' => 1.0];
        }
        $total = array_sum($effective);
        if ($total <= 0) {
            return $this->abstain($labels, $resultKey,
                ($algorithmic['reason'] ?? 'no_supported_models'), $configured, $effective, $predictions);
        }
        $effective = array_map(fn (float $weight): float => $weight / $total, $effective);

        $votes = array_fill_keys($labels, 0.0);
        $similarity = 0.0;
        $counts = $effectiveCounts = [];
        foreach ($effective as $source => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $prediction = $predictions[$source];
            foreach ($labels as $label) {
                $vote = $prediction['votes'][$label] ?? null;
                if (! is_numeric($vote) || ! is_finite((float) $vote) || $vote < 0 || $vote > 1) {
                    throw new InvalidArgumentException('Invalid training-source score.');
                }
                $votes[$label] += $weight * (float) $vote;
            }
            if (abs(array_sum($prediction['votes']) - 1.0) > 1e-9) {
                throw new InvalidArgumentException('Supported training-source scores must sum to one.');
            }
            $similarity += $weight * (float) ($prediction['similarity'] ?? 1.0);
            $counts[] = (int) ($prediction['neighbors'] ?? 0);
            $effectiveCounts[] = (float) ($prediction['effective_neighbors'] ?? 0);
        }

        arsort($votes);
        $ordered = array_values($votes);
        $winner = array_key_first($votes);
        $confidence = $ordered[0] * $similarity;
        $minimum = $this->minimumConfidence;
        $reason = abs($ordered[0] - $ordered[1]) < 1e-12 ? 'tied_source_scores'
            : ($confidence < $minimum ? 'weak_source_consensus' : 'supported');

        return [
            $resultKey => $reason === 'supported' ? $winner : ($resultKey === 'action' ? 'hodl' : 'neutral'),
            'confidence' => $reason === 'supported' ? $confidence : 0.0,
            'reason' => $reason,
            'neighbors' => $counts === [] ? 0 : min($counts),
            'effective_neighbors' => $effectiveCounts === [] ? 0.0 : min($effectiveCounts),
            'similarity' => $similarity,
            'votes' => $votes,
            'sources' => [
                'configured_weights' => $configured,
                'effective_weights' => $effective,
                'human_samples' => $humanSamples,
                'components' => $predictions,
            ],
        ];
    }

    private function abstain(array $labels, string $resultKey, string $reason, array $configured, array $effective, array $predictions): array
    {
        return [
            $resultKey => $resultKey === 'action' ? 'hodl' : 'neutral',
            'confidence' => 0.0, 'reason' => $reason,
            'neighbors' => 0, 'effective_neighbors' => 0.0, 'similarity' => 0.0,
            'votes' => array_fill_keys($labels, 0.0),
            'sources' => ['configured_weights' => $configured, 'effective_weights' => $effective,
                'human_samples' => 0, 'components' => $predictions],
        ];
    }
}
