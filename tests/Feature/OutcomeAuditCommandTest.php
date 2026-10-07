<?php

use App\Domain\Intelligence\OutcomeAudit;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/trademinator-outcome-audit-'.Str::uuid7();
    config([
        'research.path' => $path.'/research',
        'intelligence.path' => $path.'/models',
        'archive.enabled' => false,
        'intelligence.patterns.enabled' => false,
        'intelligence.knn.min_train_size' => 36,
        'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5,
        'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.outcome.min_supported_predictions' => 5,
    ]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

it('audits Outcome research without publishing or evaluating the reserved final holdout', function () {
    $this->travelTo('2024-01-01 06:00:00 UTC');
    IntelligenceFixtures::candles(280);
    $manifest = IntelligenceFixtures::snapshot(240);

    $result = app(OutcomeAudit::class)->run($manifest['dataset_id'], 5, microtime(true) + 60);

    expect($result['read_only'])->toBeTrue()
        ->and($result['selection_policy'])->toBe('fixed_k_research_only')
        ->and($result['horizon_candidates'])->toBe([1, 2, 3, 4])
        ->and($result['research']['horizons'])->toHaveKeys(['1', '2', '3', '4'])
        ->and($result['research']['horizons']['2']['five_class'])->toHaveKeys(['supported', 'accuracy', 'macro_f1'])
        ->and($result['research']['horizons']['2']['three_class'])->toHaveKeys(['accuracy', 'macro_f1', 'per_class'])
        ->and($result['research']['horizons']['2']['ordinal'])->toHaveKeys([
            'exact_accuracy', 'within_one_class_accuracy', 'mean_absolute_class_error',
            'same_direction_accuracy', 'opposite_direction_rate', 'extreme_opposite_rate',
        ])
        ->and($result['research']['feature_groups_at_current_horizon'])->toHaveKeys([
            'core', 'technical', 'technical_plus_patterns',
        ])
        ->and($result['final_holdout']['evaluated'])->toBeFalse()
        ->and($result['final_holdout']['reason'])->toBe('reserved_for_post_research_validation');

    $this->assertDatabaseCount('research_datasets', 1);
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseCount('intelligence_heads', 0);

    $this->artisan('trademinator:outcome-audit', [
        'exchange' => 'kraken',
        'symbol' => 'BTC/USD',
        'period' => '1m',
        '--dataset' => $manifest['dataset_id'],
        '--k' => 5,
        '--timeout' => 60,
    ])->expectsOutputToContain('"read_only": true')->assertSuccessful();
});
