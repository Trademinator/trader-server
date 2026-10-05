<?php

return [
    'enabled' => env('HUMAN_TRAINING_ENABLED', true),
    // Both are optional enhancements, never model-readiness prerequisites.
    'trend_enabled' => env('HUMAN_TREND_TRAINING_ENABLED', true),
    'candle_enabled' => env('HUMAN_CANDLE_TRAINING_ENABLED', true),
    'auxiliary_max_seconds' => 90,
    'publication_reserve_seconds' => 10,
    'trainer_uuids' => array_values(array_filter(array_map('trim', explode(',', (string) env('HUMAN_TRAINING_TRAINER_UUIDS', ''))))),
    'chart_candles' => 90,
    'candidate_attempts' => 24,
    'assignment_minutes' => 60,
    'min_samples' => 50,
    'min_reviewers' => 1,
    'min_agreement' => 0.67,
    'k' => 9,
    'min_precision_gain' => 0.02,
    'candle_min_samples' => 50,
    // Compared against natural frequencies on chronological tuning data only.
    'candle_target_weights' => ['buy' => 0.25, 'hold' => 0.50, 'sell' => 0.25],
    'candle_k' => 9,
    'candle_min_precision_gain' => 0.02,
];
