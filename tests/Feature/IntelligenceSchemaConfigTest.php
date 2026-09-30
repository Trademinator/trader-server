<?php

use App\Jobs\RebuildBackfilledIntelligence;
use App\Jobs\TrainMarketIntelligence;

it('captures the configured intelligence schema in queued intelligence jobs', function () {
    config(['intelligence.schema' => 'core']);
    $weeklyCore = new TrainMarketIntelligence('kraken', 'BTC/USD', '1h', '2026-09-28');
    $backfillCore = new RebuildBackfilledIntelligence('history-1', 'lease-1');

    config(['intelligence.schema' => 'full']);
    $weeklyFull = new TrainMarketIntelligence('kraken', 'BTC/USD', '1h', '2026-09-28');
    $backfillFull = new RebuildBackfilledIntelligence('history-1', 'lease-1');

    expect($weeklyCore->schema)->toBe('core')
        ->and($weeklyFull->schema)->toBe('full')
        ->and($weeklyCore->uniqueId())->not->toBe($weeklyFull->uniqueId())
        ->and($backfillCore->schema)->toBe('core')
        ->and($backfillFull->schema)->toBe('full');
});

it('allows an explicit queued schema to override the configured default', function () {
    config(['intelligence.schema' => 'core']);

    $job = new TrainMarketIntelligence('kraken', 'BTC/USD', '1h', '2026-09-28', 'technical');

    expect($job->schema)->toBe('technical');
});
