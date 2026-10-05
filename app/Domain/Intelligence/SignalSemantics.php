<?php

namespace App\Domain\Intelligence;

final class SignalSemantics
{
    public static function actionMeaning(string $action, string $reason, ?array $scoring = null): ?string
    {
        if ($reason !== 'supported') {
            return null;
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
