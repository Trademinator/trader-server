<?php

use App\Domain\Intelligence\HumanTraining;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\OutcomeTrainingSession;
use App\Domain\Research\DatasetStore;
use App\Models\Exchange;
use App\Models\HumanTrainingReview;
use App\Models\HumanTrainingSnapshot;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['research.path' => sys_get_temp_dir().'/outcome-session-'.Str::uuid7(),
        'human_training.enabled' => true]);
});

afterEach(function () {
    File::deleteDirectory(config('research.path'));
});

/** Build two or more independently trainable markets with real, closed source bars. */
function outcomeSessionDataset(User $subscriber, string $exchangeClass, string $symbol, int $count = 12): array
{
    $manifest = IntelligenceFixtures::snapshot($count);
    $manifest['exchange'] = $exchangeClass;
    $manifest['symbol'] = $symbol;
    $directory = app(DatasetStore::class)->directory($manifest['dataset_id']);
    file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])
        ->update(['manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)]);

    for ($i = -1; $i <= $count + 2; $i++) {
        Ticker::query()->create(['exchange' => $exchangeClass, 'symbol' => $symbol, 'period' => '1m',
            'microtimestamp' => IntelligenceFixtures::START + $i * 60000,
            'payload' => json_encode(['open' => '10', 'high' => '11', 'low' => '9', 'close' => '10', 'volume' => '1'])]);
    }
    $exchange = Exchange::query()->firstOrCreate(['class' => $exchangeClass],
        ['name' => ucfirst($exchangeClass), 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id,
        'symbol' => $symbol, 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m']);
    MarketSubscription::query()->create(['user_id' => $subscriber->user_id, 'market_id' => $market->market_id, 'active' => true]);

    return $manifest;
}

/** Persist a complete historical human review on a specific market (for balancing). */
function outcomeSessionPastAssessments(string $exchange, string $symbol, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $payload = HumanTrainingSnapshot::factory()->make()->payload;
        $payload['exchange'] = $exchange;
        $payload['symbol'] = $symbol;
        $marketKey = ModelStore::marketKey($exchange, $symbol, '1m');
        $snapshot = HumanTrainingSnapshot::factory()->create([
            'market_key' => $marketKey,
            'snapshot_key' => hash('sha256', $marketKey.'|'.$payload['decision_at_ms'].'|'.HumanTraining::VERSION),
            'payload' => $payload,
            'sha256' => HumanTrainingSnapshot::digest($payload),
        ]);
        HumanTrainingReview::factory()->create(['snapshot_id' => $snapshot->snapshot_id,
            'label' => 'bear', 'submitted_at' => now()]);
    }
}

it('shows both save buttons and continues on the same exchange and pair without selection', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $manifest = outcomeSessionDataset($trainer, 'kraken', 'BTC/USD');
    $first = app(HumanTraining::class)->assign($trainer, $manifest['dataset_id']);
    $url = route('human-training.show', $first->review_id);

    $this->actingAs($trainer)->get($url)->assertOk()
        ->assertSee('Save Outcome assessment')
        ->assertSee('Save Outcome assessment &amp; choose random scenario', false);

    $response = $this->put(route('human-training.update', $first->review_id), [
        'label' => 'bull', 'next' => OutcomeTrainingSession::SAME_MARKET,
    ])->assertRedirect();

    $second = HumanTrainingReview::query()->where('trainer_id', $trainer->user_id)->whereNull('submitted_at')->sole();
    $response->assertRedirect(route('human-training.show', $second->review_id));
    expect($second->review_id)->not->toBe($first->review_id);
    expect($second->snapshot->market_key)->toBe(ModelStore::marketKey('kraken', 'BTC/USD', '1m'));
    $this->assertDatabaseHas('human_training_reviews', ['review_id' => $first->review_id, 'label' => 'bull']);
    $this->assertDatabaseCount('human_training_reviews', 2);
});

it('routes a saved assessment to the least-trained different exchange and pair', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $source = outcomeSessionDataset($trainer, 'kraken', 'BTC/USD');
    outcomeSessionDataset($trainer, 'bitso', 'ATOM/USD');
    outcomeSessionDataset($trainer, 'coinbase', 'XRP/USD');
    outcomeSessionPastAssessments('bitso', 'ATOM/USD', 3);

    $first = app(HumanTraining::class)->assign($trainer, $source['dataset_id']);
    $response = $this->actingAs($trainer)->put(route('human-training.update', $first->review_id), [
        'label' => 'neutral', 'next' => OutcomeTrainingSession::LEAST_TRAINED,
    ])->assertRedirect();

    $second = HumanTrainingReview::query()->where('trainer_id', $trainer->user_id)->whereNull('submitted_at')->sole();
    $response->assertRedirect(route('human-training.show', $second->review_id));
    expect($second->snapshot->market_key)->toBe(ModelStore::marketKey('coinbase', 'XRP/USD', '1m'));
    expect($second->snapshot->market_key)->not->toBe($first->snapshot->market_key);
    $this->assertDatabaseHas('human_training_reviews', ['review_id' => $first->review_id, 'label' => 'neutral']);
});

it('tries the next least-trained pair when the first one is exhausted for this trainer', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $source = outcomeSessionDataset($trainer, 'kraken', 'BTC/USD');
    $exhausted = outcomeSessionDataset($trainer, 'bitso', 'ATOM/USD', count: 1);
    outcomeSessionDataset($trainer, 'coinbase', 'XRP/USD');
    outcomeSessionPastAssessments('coinbase', 'XRP/USD', 2);

    $seen = app(HumanTraining::class)->assign($trainer, $exhausted['dataset_id']);
    app(HumanTraining::class)->submit($trainer, $seen->review_id, 'bear', null, null);
    $first = app(HumanTraining::class)->assign($trainer, $source['dataset_id']);
    $this->actingAs($trainer)->put(route('human-training.update', $first->review_id), [
        'label' => 'bull', 'next' => OutcomeTrainingSession::LEAST_TRAINED,
    ])->assertRedirect();

    $second = HumanTrainingReview::query()->where('trainer_id', $trainer->user_id)->whereNull('submitted_at')->sole();
    expect($second->snapshot->market_key)->toBe(ModelStore::marketKey('coinbase', 'XRP/USD', '1m'));
});

it('preserves the saved assessment and returns to selection when no different pair is available', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $source = outcomeSessionDataset($trainer, 'kraken', 'BTC/USD');
    $first = app(HumanTraining::class)->assign($trainer, $source['dataset_id']);

    $this->actingAs($trainer)->put(route('human-training.update', $first->review_id), [
        'label' => 'super_bull', 'next' => OutcomeTrainingSession::LEAST_TRAINED,
    ])->assertRedirect(route('human-training.index'))->assertSessionHas('status');

    expect($first->fresh()->label)->toBe('super_bull');
    $this->assertDatabaseCount('human_training_reviews', 1);
});

it('skips the current snapshot and continues in the same exchange/pair', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $source = outcomeSessionDataset($trainer, 'kraken', 'BTC/USD');
    $first = app(HumanTraining::class)->assign($trainer, $source['dataset_id']);

    $response = $this->actingAs($trainer)->put(route('human-training.update', $first->review_id), ['label' => 'skip']);
    $second = HumanTrainingReview::query()->where('trainer_id', $trainer->user_id)->whereNull('submitted_at')->sole();

    $response->assertRedirect(route('human-training.show', $second->review_id));
    expect($first->fresh()->label)->toBe('skip');
    expect($second->snapshot->market_key)->toBe($first->snapshot->market_key);
});

it('rejects unknown continuation modes before saving the assessment', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $source = outcomeSessionDataset($trainer, 'kraken', 'BTC/USD');
    $first = app(HumanTraining::class)->assign($trainer, $source['dataset_id']);

    $this->actingAs($trainer)->put(route('human-training.update', $first->review_id), [
        'label' => 'bull', 'next' => 'other_trainer_market',
    ])->assertSessionHasErrors('next');

    expect($first->fresh()->submitted_at)->toBeNull();
});
