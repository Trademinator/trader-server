<?php

namespace App\Domain\Intelligence;

final class SignalSemantics
{
    public static function actionMeaning(string $action, string $reason, ?array $scoring = null): ?string
    {
        if ($reason === 'degraded_action_only') {
            return $action === 'sell' ? 'degraded_sell_by_action_knn_without_outcome_confirmation' : null;
        }
        if ($reason !== 'supported') {
            return null;
        }
        if (in_array(($scoring['version'] ?? null), ['outcome-action-matrix-v1', 'outcome-action-matrix-v2'], true)) {
            return match ($action) {
                'buy' => 'supported_buy_by_outcome_action_matrix',
                'sell' => 'supported_sell_by_outcome_action_matrix',
                default => null,
            };
        }
        if (($scoring['version'] ?? null) === KnnEnsemble::VERSION) {
            return match ($action) {
                'buy' => 'supported_buy_by_weighted_models',
                'sell' => 'supported_sell_by_weighted_models',
                default => null,
            };
        }

        return match ($action) {
            'buy' => 'supported_bottom_with_upward_future_move',
            'sell' => 'supported_top_with_downward_future_move',
            default => null,
        };
    }
}
