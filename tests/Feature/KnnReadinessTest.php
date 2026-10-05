<?php

use App\Domain\Intelligence\HumanCandleKnn;
use App\Domain\Intelligence\IntelligenceTrainer;

it('shows each KNN readiness independently of the combined build status', function (array $overrides, array $settings, bool $automatic, bool $human) {
    $this->freezeTime();
    config(array_replace(['human_training.enabled' => true, 'human_training.candle_enabled' => true,
        'intelligence.max_model_age_days' => 14], $settings));
    $report = array_replace_recursive([
        'status' => 'ready', 'validation_version' => IntelligenceTrainer::VERSION,
        'trained_as_of_ms' => now()->getTimestampMs(),
        'automatic' => ['status' => 'ready', 'reason' => 'validated'],
        'candle_guidance' => ['version' => HumanCandleKnn::VERSION, 'status' => 'validated', 'influence' => true],
        'ensemble' => ['weights' => ['automatic' => 0.4, 'human_candle' => 0.6]],
    ], $overrides);

    $view = $this->blade('<x-knn-readiness :report="$report" />', ['report' => $report]);

    $view->assertSee('aria-label="Automatic KNN: '.($automatic ? 'Ready' : 'Not ready'), false)
        ->assertSee('aria-label="Human Candle KNN: '.($human ? 'Ready' : 'Not ready'), false);
    expect(substr_count((string) $view, '>✓</span>'))->toBe((int) $automatic + (int) $human);
})->with([
    'both models ready' => [[], [], true, true],
    'automatic only' => [['candle_guidance' => ['status' => 'insufficient_candle_labels', 'influence' => false]], [], true, false],
    'human only' => [['automatic' => ['status' => 'abstaining', 'reason' => 'holdout_failed']], [], false, true],
    'automatic status takes precedence over a contradictory reason' => [['automatic' => ['status' => 'abstaining']], [], false, true],
    'neither validated' => [['status' => 'abstaining', 'automatic' => ['status' => 'abstaining', 'reason' => 'no_eligible_k'],
        'candle_guidance' => ['status' => 'tuning_failed', 'influence' => false]], [], false, false],
    'combined status cannot stand in for component results' => [['automatic' => null, 'candle_guidance' => null], [], false, false],
    'expired build' => [['trained_as_of_ms' => 0], [], false, false],
    'obsolete build version' => [['validation_version' => 'old'], [], false, false],
    'obsolete human model version' => [['candle_guidance' => ['version' => 'old']], [], true, false],
    'human influence unavailable' => [['candle_guidance' => ['influence' => false]], [], true, false],
    'human training disabled' => [[], ['human_training.enabled' => false], true, false],
    'candle training disabled' => [[], ['human_training.candle_enabled' => false], true, false],
    'automatic has zero published scoring weight' => [['ensemble' => ['weights' => ['automatic' => 0]]], [], false, true],
    'human has zero published scoring weight' => [['ensemble' => ['weights' => ['human_candle' => 0]]], [], true, false],
    'unpublished build' => [['status' => 'abstaining'], [], false, false],
]);

it('keeps both labelled placeholders when no model has been built', function () {
    $this->blade('<x-knn-readiness />')
        ->assertSee('Automatic KNN: Not ready — No model built yet')
        ->assertSee('Human Candle KNN: Not ready — No model built yet')
        ->assertDontSee('✓');
});

it('shows accessible separate readiness totals including zero', function () {
    $this->blade('<x-knn-readiness :counts="$counts" :total="4" />', ['counts' => ['automatic' => 3, 'human_candle' => 0]])
        ->assertSee('Automatic KNN: 3 of 4 ready')
        ->assertSee('Human Candle KNN: 0 of 4 ready');
});

it('escapes diagnostic reasons in readiness tooltips', function () {
    $this->freezeTime();
    $report = ['status' => 'abstaining', 'validation_version' => IntelligenceTrainer::VERSION,
        'trained_as_of_ms' => now()->getTimestampMs(),
        'automatic' => ['status' => 'abstaining', 'reason' => '<script>alert(1)</script>']];

    $this->blade('<x-knn-readiness :report="$report" />', ['report' => $report])
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});
