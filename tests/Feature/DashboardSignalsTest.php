<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\SignalJournal;
use App\Domain\Operations\ActionContext;
use App\Domain\Operations\ActionLog;
use App\Jobs\RecordMarketSignal;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\IntelligenceFixtures;

it('records waiting once for shared subscribers and stops recording after the last unsubscribe', function () {
    $this->freezeTime();
    $fixture = MarketSignal::factory()->create();
    $market = $fixture->market;
    $fixture->delete();
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    foreach (User::factory()->count(2)->create() as $user) {
        MarketSubscription::query()->create(['user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    }
    $journal = app(SignalJournal::class);
    $signal = $journal->record($market);
    expect($signal->reason)->toBe('no_model')->and($signal->payload['execution_status'])->toBe('unknown');
    $this->travel(1)->minutes();
    expect($journal->record($market)->recorded_at_ms)->toBe($signal->recorded_at_ms);
    $this->assertDatabaseCount('market_signals', 1);
    $market->feed->update(['selected_period' => '1h']);
    $journal->record($market);
    $this->travel(1)->minutes();
    $market->feed->update(['selected_period' => '1m']);
    $recovery = $journal->record($market);
    expect($recovery->recorded_at_ms)->toBeGreaterThan($signal->recorded_at_ms)->and($recovery->is_change)->toBeTrue();
    $this->assertDatabaseCount('market_signals', 3);
    expect(fn () => $signal->update(['action' => 'buy']))->toThrow(LogicException::class);
    $market->subscriptions()->update(['active' => false]);
    expect($journal->record($market))->toBeNull();
});

it('records the original model evidence, preserves its time and distinguishes supported HOLD from waiting', function () {
    $this->travelTo('2024-01-01 04:05:00 UTC');
    $path = sys_get_temp_dir().'/trademinator-dashboard-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.knn.train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'intelligence.horizon' => 2, 'intelligence.lookback' => 3]);
    $fixture = MarketSignal::factory()->create();
    $market = $fixture->market;
    $fixture->delete();
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    $owner = User::factory()->create();
    MarketSubscription::query()->create(['user_id' => $owner->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    try {
        $manifest = IntelligenceFixtures::snapshot();
        $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
        $handler = new TestHandler;
        app()->instance(ActionLog::class, new ActionLog(new Logger('test-actions', [$handler]), app(ActionContext::class)));
        IntelligenceFixtures::feature(243, 0.5);
        IntelligenceFixtures::feature(244, 0.5);
        $first = app(SignalJournal::class)->record($market);
        expect($first->reason)->toBe('supported')->and($first->action)->toBe('hodl')
            ->and($first->model_id)->toBe($report['model_id'])->and($first->payload['horizon_candles'])->toBe(2);
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('HOLD')->assertSee('Model validated');
        $this->travelTo('2024-01-01 04:08:00 UTC');
        $second = app(SignalJournal::class)->record($market);
        expect($second->reason)->toBe('stale_features')->and($second->is_change)->toBeTrue();
        expect($first->fresh()->payload)->toBe($first->payload);
        $this->assertDatabaseCount('market_signals', 2);
        $predictions = collect($handler->getRecords())->map(fn ($record) => json_decode($record->message, true))
            ->where('event', 'intelligence.predicted')->values();
        expect($predictions)->toHaveCount(2);
        expect($predictions[0])->toMatchArray(['model_id' => $first->model_id, 'action' => 'hodl', 'reason' => 'supported']);
        expect($predictions[1])->toMatchArray(['model_id' => $second->model_id, 'reason' => 'stale_features']);
    } finally {
        File::deleteDirectory($path);
    }
});

it('queues one recording per shared active market, rechecks the subscription in the job, and rejects sync dispatch', function () {
    config(['queue.default' => 'database']);
    Queue::fake([RecordMarketSignal::class]);
    $fixture = MarketSignal::factory()->create();
    $market = $fixture->market;
    $fixture->delete();
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    foreach (User::factory()->count(2)->create() as $user) {
        MarketSubscription::query()->create(['user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    }
    $this->artisan('trademinator:dispatch-market-signals')->assertSuccessful();
    Queue::assertPushed(RecordMarketSignal::class, 1);
    Queue::assertPushed(RecordMarketSignal::class, fn ($job) => $job->marketId === $market->getKey() && $job->queue === config('intelligence.queue'));
    $market->subscriptions()->update(['active' => false]);
    (new RecordMarketSignal($market->getKey()))->handle(app(SignalJournal::class));
    $this->assertDatabaseCount('market_signals', 0);
    config(['queue.default' => 'sync']);
    $this->artisan('trademinator:dispatch-market-signals')->assertFailed();
});
