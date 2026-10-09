<?php

namespace App\Domain\Intelligence;

final class SignalSemantics
{
    /** Only BUY and SELL are actionable Client API values; abstentions are HOLD. */
    public static function clientAction(string $action, string $reason): string
    {
        if (! in_array($reason, ['supported', 'degraded_action_only'], true)
            || ($reason === 'degraded_action_only' && $action === 'buy')) {
            return 'hold';
        }

        return in_array($action, ['buy', 'sell'], true) ? $action : 'hold';
    }

    public static function evidenceStatus(string $reason): string
    {
        return match ($reason) {
            'supported' => 'supported',
            'degraded_action_only' => 'degraded',
            default => 'abstaining',
        };
    }

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
