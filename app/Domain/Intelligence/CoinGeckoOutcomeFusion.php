<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\SemanticLabels;

/**
 * Auxiliary CoinGecko evidence can veto or reduce confidence, never create a
 * new BUY/SELL that would not have been supported by the primary KNNs.
 */
final class CoinGeckoOutcomeFusion
{
    public function combine(array $core, array $insight, array $bundle, float $minimumConfidence): array
    {
        $meta = [
            'enabled' => (bool) config('intelligence.coingecko_insight.enabled'),
            'influence_enabled' => (bool) config('intelligence.coingecko_insight.influence_enabled'),
            'applied' => false, 'weight' => 0.0,
            'reason' => 'optional_unavailable',
        ];
        if (($core['reason'] ?? null) !== 'supported') {
            return ['outcome' => $core, 'fusion' => [...$meta, 'reason' => 'primary_outcome_unsupported']];
        }
        if (($insight['reason'] ?? null) !== 'supported' || ($bundle['status'] ?? null) !== 'validated') {
            return ['outcome' => $core, 'fusion' => [...$meta, 'reason' => $insight['reason'] ?? 'unavailable']];
        }
        if (! $meta['influence_enabled']) {
            return ['outcome' => $core, 'fusion' => [...$meta, 'reason' => 'shadow_mode']];
        }
        $gain = max(0.0, (float) ($bundle['holdout']['f1_improvement'] ?? 0.0));
        $cap = max(0.0, min(0.15, (float) config('intelligence.coingecko_insight.max_weight', 0.15)));
        $weight = $cap * min(1.0, $gain / 0.10);
        if ($weight <= 0) {
            return ['outcome' => $core, 'fusion' => [...$meta, 'reason' => 'no_incremental_weight']];
        }

        $votes = [];
        foreach (SemanticLabels::OUTCOMES as $label) {
            $votes[$label] = (1 - $weight) * (float) ($core['votes'][$label] ?? 0)
                + $weight * (float) ($insight['votes'][$label] ?? 0);
        }
        arsort($votes);
        $ordered = array_values($votes);
        $similarity = (float) ($core['similarity'] ?? 1.0);
        $confidence = $ordered[0] * $similarity;
        $supported = ($ordered[0] - ($ordered[1] ?? 0) > 1e-12)
            && $confidence >= $minimumConfidence;
        $outcome = $core;
        $outcome['votes'] = $votes;
        $outcome['outcome'] = $supported ? array_key_first($votes) : 'neutral';
        $outcome['confidence'] = $supported ? $confidence : 0.0;
        $outcome['reason'] = $supported ? 'supported' : 'weak_context_consensus';

        return ['outcome' => $outcome, 'fusion' => [
            ...$meta, 'applied' => true, 'weight' => $weight,
            'reason' => $supported ? 'blended' : 'context_veto',
        ]];
    }
}
