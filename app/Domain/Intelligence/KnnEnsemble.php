<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;

/** Combine independent action scores; never feed one model's answer into the other. */
final class KnnEnsemble
{
    public const VERSION = 'two-knn-v1';

    public static function settings(array $settings): array
    {
        $weights = $settings['weights'] ?? [];
        if (count($weights) !== 2 || array_diff(['automatic', 'human_candle'], array_keys($weights)) !== []) {
            throw new InvalidArgumentException('Provide automatic and human candle scoring weights.');
        }
        foreach ($weights as &$weight) {
            if (! is_numeric($weight) || ! is_finite((float) $weight) || $weight < 0) {
                throw new InvalidArgumentException('Scoring weights must be finite and nonnegative.');
            }
            $weight = (float) $weight;
        }
        unset($weight);
        $minimum = $settings['min_confidence'] ?? null;
        if (! is_finite(array_sum($weights)) || array_sum($weights) <= 0 || ! is_numeric($minimum)
            || ! is_finite((float) $minimum) || $minimum < 0.5 || $minimum > 1) {
            throw new InvalidArgumentException('Invalid ensemble evidence thresholds.');
        }

        return ['version' => self::VERSION, 'weights' => $weights, 'min_confidence' => (float) $minimum];
    }

    public function combine(array $automatic, array $human, array $settings): array
    {
        $settings = self::settings($settings);
        $predictions = ['automatic' => $automatic, 'human_candle' => $human];
        $effective = array_map(fn (): float => 0.0, $settings['weights']);
        foreach ($predictions as $name => $prediction) {
            if ($prediction['reason'] === 'supported') {
                $effective[$name] = $settings['weights'][$name];
            }
        }
        $total = array_sum($effective);
        if ($total > 0) {
            $effective = array_map(fn (float $weight): float => $weight / $total, $effective);
        }
        $scoring = ['version' => self::VERSION, 'configured_weights' => $settings['weights'],
            'effective_weights' => $effective, 'components' => [],
            'human_trend' => ['status' => 'excluded', 'weight' => 0.0],
            'social_news' => ['status' => 'not_implemented', 'weight' => 0.0]];
        foreach ($predictions as $name => $prediction) {
            $scoring['components'][$name] = [
                'action' => $prediction['action'] === 'hodl' ? 'hold' : $prediction['action'],
                'reason' => $prediction['reason'], 'confidence' => $prediction['confidence'],
                'scores' => $this->scores($prediction['votes']),
                'similarity' => $prediction['similarity'], 'neighbors' => $prediction['neighbors'],
                'effective_neighbors' => $prediction['effective_neighbors'],
            ];
        }
        if ($total <= 0) {
            return [...WeightedKnn::abstain($automatic['reason'] === 'supported' ? 'no_supported_models' : $automatic['reason']),
                'scoring' => [...$scoring, 'scores' => $this->scores([])]];
        }
        $votes = ['buy' => 0.0, 'hodl' => 0.0, 'sell' => 0.0];
        $similarity = 0.0;
        $counts = $effectiveCounts = [];
        foreach ($effective as $name => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $prediction = $predictions[$name];
            foreach ($votes as $action => $_) {
                $vote = $prediction['votes'][$action] ?? null;
                if (! is_numeric($vote) || ! is_finite((float) $vote) || $vote < 0 || $vote > 1) {
                    throw new InvalidArgumentException('Invalid component action score.');
                }
                $votes[$action] += $weight * $vote;
            }
            if (abs(array_sum($prediction['votes']) - 1.0) > 1e-9) {
                throw new InvalidArgumentException('Supported component scores must sum to one.');
            }
            $similarity += $weight * $prediction['similarity'];
            $counts[] = $prediction['neighbors'];
            $effectiveCounts[] = $prediction['effective_neighbors'];
        }
        arsort($votes);
        $ordered = array_values($votes);
        $confidence = $ordered[0] * $similarity;
        $reason = abs($ordered[0] - $ordered[1]) < 1e-12 ? 'tied_model_scores'
            : ($confidence < $settings['min_confidence'] ? 'weak_model_consensus' : 'supported');

        return ['action' => $reason === 'supported' ? array_key_first($votes) : 'hodl',
            'confidence' => $reason === 'supported' ? $confidence : 0.0, 'reason' => $reason,
            // Both models may reuse the same candles. Never add their evidence counts.
            'neighbors' => min($counts), 'effective_neighbors' => min($effectiveCounts),
            'similarity' => $similarity, 'votes' => $votes,
            'scoring' => [...$scoring, 'scores' => $this->scores($votes)]];
    }

    private function scores(array $votes): array
    {
        return ['buy' => $votes['buy'] ?? 0.0, 'hold' => $votes['hodl'] ?? 0.0, 'sell' => $votes['sell'] ?? 0.0];
    }
}
