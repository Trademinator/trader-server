<?php

namespace App\Domain\Intelligence;

final class SignalSemantics
{
    public static function actionMeaning(string $action, string $reason): ?string
    {
        if ($reason !== 'supported') {
            return null;
        }

        return match ($action) {
            'buy' => 'supported_bottom_with_upward_future_move',
            'sell' => 'supported_top_with_downward_future_move',
            default => null,
        };
    }
}
