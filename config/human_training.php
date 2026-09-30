<?php

return [
    'enabled' => env('HUMAN_TRAINING_ENABLED', true),
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
    'candle_k' => 9,
    'candle_min_precision_gain' => 0.02,
];
