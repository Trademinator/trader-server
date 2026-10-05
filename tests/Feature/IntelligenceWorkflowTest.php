<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use App\Jobs\TrainMarketIntelligence;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/trademinator-m4-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'intelligence.horizon' => 2, 'intelligence.lookback' => 3]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

it('freezes semantic labels and pattern outcomes only after their genuine candle closes', function () {
    $this->travelTo('2024-01-01 02:00:00 UTC');
    IntelligenceFixtures::candles();
    $cutoff = IntelligenceFixtures::START + 90 * 60000;

    $manifest = app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m',
        new SemanticLabels(2, 3), asOfMs: $cutoff);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);

    expect($manifest['label_definition']['fee_bps'])->toBe(0);
    expect($manifest['label_definition']['cost_model'])->toBe('none');
    expect($manifest['label_definition']['version'])->toBe(SemanticLabels::VERSION);
    expect($manifest['label_counts']['buy'])->toBeGreaterThan(0);
    expect($manifest['label_counts']['sell'])->toBeGreaterThan(0);
    foreach ($rows as $row) {
        expect($row['label_available_at_ms'])->toBeLessThanOrEqual($cutoff)
            ->and($row)->toHaveKeys(['gross_return', 'buy_price_return', 'sell_base_price_return'])
            ->not->toHaveKeys(['buy_net_return', 'sell_base_net_return']);
        foreach ($row['patterns'] as $pattern) {
            expect($pattern['label_available_at_ms'])->toBeLessThanOrEqual($cutoff);
        }
    }
});

it('publishes a validated model with separate chronological tuning and untouched holdout metrics', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();

    $this->artisan('trademinator:knn-build', ['exchange' => 'kraken', 'symbol' => 'BTC/USD',
        'period' => '1m', '--dataset' => $manifest['dataset_id']])->assertSuccessful();
    $artifact = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m');

    expect($artifact['status'])->toBe('ready');
    expect($artifact['holdout']['semantic_precision'])->toBe(1);
    expect($artifact['holdout_training_labels_available_by_ms'])->toBeLessThan($artifact['holdout_from_ms']);
    expect($artifact['knowledge_rows'])->toBe(240);
    $this->assertDatabaseCount('intelligence_models', 1);
    $this->assertDatabaseCount('intelligence_heads', 1);
    $this->artisan('trademinator:model-info', ['model' => $artifact['model_id']])->assertSuccessful();
    expect(app(ModelStore::class)->report($artifact['model_id']))->not->toHaveKey('knowledge');
});

it('keeps final holdout targets out of K selection', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $first = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']);
    $lines = file($path.'/rows.jsonl');
    foreach ($lines as $i => &$line) {
        if ($i >= 192) {
            $row = json_decode($line, true);
            $row['label'] = 'hodl';
            $line = json_encode($row)."\n";
        }
    }
    unset($line);
    $bytes = implode('', $lines);
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    file_put_contents($path.'/rows.jsonl', $bytes);
    file_put_contents($path.'/manifest.json', json_encode($manifest));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])->update(['manifest' => json_encode($manifest)]);

    $second = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);

    expect($second['selection'])->toBe($first['selection']);
    expect($second['status'])->toBe('abstaining');
    expect($second['reason'])->toBe('holdout_failed');
});

it('predicts from closed features while ignoring an open candle and abstains when data becomes stale', function () {
    $this->travelTo('2024-01-01 04:05:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    IntelligenceFixtures::feature(243, 0.0);
    IntelligenceFixtures::feature(244, 0.0);
    IntelligenceFixtures::feature(245, 1.0);

    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');

    expect($signal['action'])->toBe('buy');
    expect($signal['decision_at_ms'])->toBe(IntelligenceFixtures::START + 245 * 60000);
    expect($signal['reference_price_source'])->toBe('closed_candle_close');
    expect((float) $signal['reference_price'])->toBeGreaterThan(0);
    $this->travelTo('2024-01-01 04:08:00 UTC');
    expect(app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m')['reason'])->toBe('stale_features');
});

it('publishes an abstaining model when no semantically valid K exists', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot(240, true);

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);

    expect($report['status'])->toBe('abstaining');
    expect($report['k'])->toBeNull();
    expect($report['reason'])->toBe('no_eligible_k');
});

it('fails safely on corrupted artifacts and rejects model path traversal', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    file_put_contents(app(ModelStore::class)->path($report['model_id']), 'corrupt');

    expect(fn () => app(ModelStore::class)->current('kraken', 'BTC/USD', '1m'))->toThrow(RuntimeException::class, 'checksum');
    $this->artisan('trademinator:model-info', ['model' => '../../.env'])->assertFailed();
});

it('does not train a second model while the market lock is held', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $lock = Cache::lock('trademinator:intelligence:'.ModelStore::marketKey('kraken', 'BTC/USD', '1m'), 720);
    $lock->get();
    try {
        expect(fn () => app(IntelligenceTrainer::class)->train($manifest['dataset_id']))
            ->toThrow(RuntimeException::class, 'already running');
        $this->assertDatabaseCount('intelligence_models', 0);
    } finally {
        $lock->release();
    }
});

it('dispatches one intelligence job for a shared market and rejects synchronous dispatch', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['queue.default' => 'database']);
    Queue::fake([TrainMarketIntelligence::class]);
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'active']);
    foreach (User::factory()->count(2)->create() as $user) {
        MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);
    }

    $this->artisan('trademinator:dispatch-market-intelligence')->assertSuccessful();

    Queue::assertPushed(TrainMarketIntelligence::class, fn ($job) => $job->queue === 'intelligence'
        && $job->exchange === 'kraken' && $job->symbol === 'BTC/USD' && $job->week === '2024-01-01');
    config(['queue.default' => 'sync']);
    $this->artisan('trademinator:dispatch-market-intelligence')->assertFailed();
});

it('builds once per weekly job even if the queue delivers it again', function () {
    $this->travelTo('2024-01-01 02:00:00 UTC');
    IntelligenceFixtures::candles();
    $job = new TrainMarketIntelligence('kraken', 'BTC/USD', '1m', '2024-01-01');

    $job->handle(app(MarketIntelligence::class));
    Cache::forget('trademinator:intelligence-week:'.$job->uniqueId());
    $job->handle(app(MarketIntelligence::class));

    $this->assertDatabaseCount('intelligence_models', 1);
    $this->assertDatabaseCount('research_datasets', 1);
});

it('returns zero confidence without any model and rejects mismatched frozen markets', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');
    expect($signal['action'])->toBe('hodl');
    expect($signal['confidence'])->toBe(0.0);
    $manifest = IntelligenceFixtures::snapshot();
    $this->artisan('trademinator:knn-build', ['exchange' => 'bitso', 'symbol' => 'BTC/USD',
        'period' => '1m', '--dataset' => $manifest['dataset_id']])->assertFailed();
    $this->assertDatabaseCount('intelligence_models', 0);
});

it('stacks only probabilities from a pattern model validated strictly before each KNN sample', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['intelligence.patterns.enabled' => true, 'intelligence.patterns.min_samples' => 30,
        'intelligence.patterns.min_block_rows' => 5, 'intelligence.patterns.trees' => 5]);
    mt_srand(42);
    $manifest = IntelligenceFixtures::snapshot(patterns: true);

    app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $artifact = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m');

    expect($artifact['pattern_keys'])->toBe(['pattern.bullish_engulfing.probability', 'pattern.bullish_engulfing.present']);
    $knownAt = $artifact['patterns']['models']['bullish_engulfing']['available_at_ms'];
    foreach ($artifact['knowledge'] as $row) {
        expect($row['decision_at_ms'])->toBeGreaterThan($knownAt);
        expect($row['vector'])->toHaveCount(3);
    }
    foreach ($artifact['selection']['folds'] as $fold) {
        expect($fold['test_from_ms'])->toBeGreaterThan($knownAt);
    }
});

it('requires rebuilding legacy model validation before issuing a new signal', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $legacy = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m');
    unset($legacy['validation_version']);
    app(ModelStore::class)->save($legacy);

    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');

    expect($signal['reason'])->toBe('model_version_mismatch');
    expect($signal['regime'])->toBe('neutral');
});

it('requires rebuilding models from the retired maximum-supply feature version', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $legacy = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m');
    $legacy['feature_version'] = 'm2-v5';
    app(ModelStore::class)->save($legacy);

    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');

    expect($signal['reason'])->toBe('model_version_mismatch');
    expect($signal['confidence'])->toBe(0.0);
});

it('describes supported direction and stronger evidence as Bull Bear or Super states', function (float $body, string $regime) {
    $this->travelTo('2024-01-01 04:05:00 UTC');
    config(['intelligence.knn.min_effective_neighbors' => 6.0]);
    $manifest = IntelligenceFixtures::snapshot();
    app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    IntelligenceFixtures::feature(243, $body);
    IntelligenceFixtures::feature(244, $body);

    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');

    expect($signal['reason'])->toBe('supported');
    expect($signal['regime'])->toBe($regime);
})->with([[0.0, 'super_bull'], [1.0, 'super_bear'], [0.21, 'bull'], [0.79, 'bear']]);

it('rejects a missing training dataset file before publishing a model', function () {
    $manifest = IntelligenceFixtures::snapshot(40);
    unlink(app(DatasetStore::class)->directory($manifest['dataset_id']).'/rows.jsonl');

    expect(fn () => app(IntelligenceTrainer::class)->train($manifest['dataset_id']))
        ->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('intelligence_models', 0);
});
