<?php

return [
    'enabled' => env('INTELLIGENCE_ENABLED', true),
    'path' => storage_path('app/private/intelligence'),
    'queue' => env('INTELLIGENCE_QUEUE', 'intelligence'),
    'schema' => env('INTELLIGENCE_SCHEMA', 'core'),
    'horizon' => 12,
    'lookback' => 20,
    'minimum_move_bps' => 10.0,
    'extreme_fraction' => 0.2,
    'max_seconds' => 480,
    // Both the training history window and the published model's maximum age.
    'max_model_age_days' => (int) env('INTELLIGENCE_MAX_MODEL_AGE_DAYS', 14),
    'max_signal_age_periods' => (int) env('INTELLIGENCE_MAX_SIGNAL_AGE_PERIODS', 2),
    'max_signal_age_seconds' => max(60, (int) env('INTELLIGENCE_MAX_SIGNAL_AGE_SECONDS', 86400)),
    // Relative action-score weights, frozen into each published model.
    'ensemble' => [
        'weights' => [
            'automatic' => (float) env('INTELLIGENCE_AUTOMATIC_WEIGHT', 0.40),
            'human_candle' => (float) env('INTELLIGENCE_HUMAN_CANDLE_WEIGHT', 0.60),
        ],
        'min_confidence' => (float) env('INTELLIGENCE_ENSEMBLE_MIN_CONFIDENCE', 0.60),
    ],
    'knn' => [
        'min_train_size' => 250, // Validation warmup only; all eligible history is retained.
        'test_size' => 100,
        'gap' => 0,
        'k_cap' => 65,
        'max_distance' => (float) env('INTELLIGENCE_KNN_MAX_DISTANCE', 0.25),
        'min_effective_neighbors' => (float) env('INTELLIGENCE_KNN_MIN_EFFECTIVE_NEIGHBORS', 3.0),
        'min_confidence' => (float) env('INTELLIGENCE_KNN_MIN_CONFIDENCE', 0.6),
        'min_validation_rows' => 50,
        'min_directional_predictions' => 5,
        'min_semantic_precision' => 0.55,
        'max_contradiction_rate' => 0.05,
        'min_coverage' => 0.01,
    ],
    'patterns' => [
        'enabled' => true,
        'as_knn_features' => true,
        'min_samples' => 100,
        'min_block_rows' => 15,
        'trees' => 50,
        'k' => 9,
    ],
];
