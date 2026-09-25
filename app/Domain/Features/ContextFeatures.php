<?php

namespace App\Domain\Features;

final class ContextFeatures
{
    public const KEYS = ['context.global_regime', 'context.btc_dominance', 'context.btc_dominance_change',
        'context.activity', 'context.activity_deviation', 'context.category_momentum',
        'context.price_deviation', 'context.market_cap_share', 'context.circulating_fraction',
        'context.volume_share'];

    public function calculate(?array $snapshot, float $close, int $asOfMs, int $maxAgeMs, ?string $category = null): array
    {
        $features = array_fill_keys(self::KEYS, null);
        if ($snapshot === null || $snapshot['observed_at_ms'] > $asOfMs || $snapshot['observed_at_ms'] < $asOfMs - $maxAgeMs || ($snapshot['payload']['expires_at_ms'] ?? PHP_INT_MAX) < $asOfMs) {
            return ['features' => $features, 'snapshot_id' => null, 'context_ready' => false];
        }
        $p = $snapshot['payload'];
        $coin = $p['coin'];
        $global = $p['global'];
        $ratio = fn ($a, $b) => FeatureEngine::ratio($a, $b);
        $bound = fn ($v, $scale = 1) => FeatureEngine::bounded(is_numeric($v) ? (float) $v : null, $scale);
        $features['context.global_regime'] = $bound($global['market_cap_change_percentage_24h_usd'] ?? null, 10);
        $features['context.btc_dominance'] = $ratio($global['market_cap_percentage']['btc'] ?? null, 100);
        $features['context.btc_dominance_change'] = $bound($p['btc_dominance_change'] ?? null, 5);
        $activity = $ratio($coin['total_volume'] ?? null, $coin['market_cap'] ?? null);
        $features['context.activity'] = $activity === null ? null : $activity / (1 + $activity);
        $features['context.activity_deviation'] = $bound($p['activity_deviation'] ?? null, 3);
        $features['context.category_momentum'] = $category === null || ($p['category_expires_at_ms'][$category] ?? 0) < $asOfMs ? null : $bound($p['categories'][$category] ?? null, 10);
        $priceRatio = $ratio($close, $coin['current_price'] ?? null);
        $features['context.price_deviation'] = $priceRatio === null ? null : $bound($priceRatio - 1, 0.05);
        $currency = $snapshot['vs_currency'];
        $features['context.market_cap_share'] = $ratio($coin['market_cap'] ?? null, $global['total_market_cap'][$currency] ?? null);
        $features['context.circulating_fraction'] = $ratio($coin['circulating_supply'] ?? null, $coin['max_supply'] ?? null);
        // Aggregate volume share is an activity/liquidity proxy, not executable order-book depth.
        $features['context.volume_share'] = $ratio($coin['total_volume'] ?? null, $global['total_volume'][$currency] ?? null);
        foreach ($features as &$value) {
            if ($value !== null) {
                $value = is_finite($value) ? max(0.0, min(1.0, $value)) : null;
            }
        }
        unset($value);

        return ['features' => $features, 'snapshot_id' => $snapshot['snapshot_id'],
            'context_ready' => ! in_array(null, $features, true)];
    }
}
