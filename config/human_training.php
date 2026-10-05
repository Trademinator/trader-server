<?php

return [
    'enabled' => env('HUMAN_TRAINING_ENABLED', true),
    // Trend annotations remain available for research, but never enter live scoring.
    'trend_enabled' => env('HUMAN_TREND_TRAINING_ENABLED', false),
    'candle_enabled' => env('HUMAN_CANDLE_TRAINING_ENABLED', true),
    'auxiliary_max_seconds' => 300,
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
    'candle_max_changes' => 3000, // Per annotation request, independent of the model history window.
    // Compared against natural frequencies on chronological tuning data only.
    'candle_target_weights' => ['buy' => 0.25, 'hold' => 0.50, 'sell' => 0.25],
    'candle_k' => 9,
    // Independent validation against human candle actions, not automatic labels.
    'candle_validation' => [
        'min_validation_rows' => 10,
        'min_directional_predictions' => 3,
    ],
    'candle_min_precision_gain' => 0.02,
];
