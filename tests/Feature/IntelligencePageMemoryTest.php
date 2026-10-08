<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\WeightedKnn;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

it('reads recorded signals and model reports without deserializing artifacts in web requests', function () {
    $this->travelTo('2024-01-01 04:05:00 UTC');
    $path = sys_get_temp_dir().'/trademinator-page-recording-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'intelligence.horizon' => 2, 'intelligence.lookback' => 3,
        'intelligence.max_signal_age_periods' => 2, 'intelligence.max_signal_age_seconds' => 3600]);
    $owner = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->getKey(), 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    $subscription = MarketSubscription::query()->create(['user_id' => $owner->getKey(), 'market_id' => $market->getKey(), 'active' => true]);

    try {
        $manifest = IntelligenceFixtures::snapshot();
        $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
        $store = app(ModelStore::class);
        expect(ModelStore::isReadyReport($report))->toBeTrue();
        expect($store->currentReport('kraken', 'BTC/USD', '1m')['model_id'])->toBe($report['model_id']);
        $decisionAt = now()->subMinute()->getTimestampMs();
        MarketSignal::factory()->create(['market_id' => $market->getKey(), 'period' => '1m',
            'model_id' => $report['model_id'], 'decision_at_ms' => $decisionAt,
            'action' => 'buy', 'reason' => 'supported',
            'payload' => [...WeightedKnn::abstain('supported'), 'action' => 'buy', 'confidence' => 0.71,
                'effective_neighbors' => 15, 'decision_at_ms' => $decisionAt, 'regime' => 'bull', 'patterns' => []]]);

        // An HTTP request must not need to open a model artifact, even if it is corrupt or larger than 128 MB.
        file_put_contents($store->path($report['model_id']), 'unreadable model artifact');
        $url = route('markets.intelligence', $subscription->getKey());
        $this->actingAs($owner)->get($url)->assertOk()
            ->assertSee('Latest recorded signal')->assertSee('Action<strong>BUY</strong>', false)
            ->assertSee('71.0%')->assertSee('Model validation');

        // Never serve an expired directional observation as a current signal.
        $this->travel(3)->minutes();
        $this->get($url)->assertOk()
            ->assertSee('Action<strong>WAITING</strong>', false)
            ->assertSee('A trained model is available, but no current recorded signal exists.')
            ->assertDontSee('Action<strong>BUY</strong>', false)
            ->assertSee('Model validation');
    } finally {
        File::deleteDirectory($path);
    }
});
