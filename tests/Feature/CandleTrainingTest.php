<?php

use App\Domain\Intelligence\CandleTraining;
use App\Domain\Research\DatasetStore;
use App\Models\Exchange;
use App\Models\HumanCandleLabel;
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
    config(['research.path' => sys_get_temp_dir().'/candle-training-test-'.Str::uuid7(), 'human_training.enabled' => true]);
});

afterEach(function () {
    File::deleteDirectory(config('research.path'));
});

function candleTrainingDataset(string $open = '11', string $close = '10', int $count = 1): array
{
    $manifest = IntelligenceFixtures::snapshot($count + 10, patterns: true);
    for ($i = 0; $i < $count + 13; $i++) {
        IntelligenceFixtures::feature($i, ($i % 3) / 2);
    }
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $rows = array_slice($rows, 10, $count);
    foreach ($rows as &$row) {
        $row['candle'] = ['open' => $open, 'close' => $close, 'high' => '12', 'low' => '9', 'volume' => '1'];
        Ticker::query()->where('exchange', 'kraken')->where('symbol', 'BTC/USD')->where('period', '1m')
            ->where('microtimestamp', $row['microtimestamp'])->update(['payload' => json_encode($row['candle'])]);
    }
    unset($row);
    $bytes = implode('', array_map(fn (array $row): string => json_encode($row)."\n", $rows));
    $manifest['rows'] = $count;
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']);
    file_put_contents($path.'/rows.jsonl', $bytes);
    file_put_contents($path.'/manifest.json', json_encode($manifest));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])->update(['manifest' => json_encode($manifest)]);

    return $manifest;
}

it('shows Candle Training separately from Trend Training', function () {
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);

    $this->actingAs($user)->get('/human-training')->assertOk()
        ->assertSee('Trend Training')->assertSee('Candle Training')
        ->assertSee('Unlabelled candles mean no human opinion');
});

it('creates editable candle labels, reports balance, and removes them back to unlabelled', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $manifest = candleTrainingDataset();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $decision = $rows[0]['decision_at_ms'];
    $training = app(CandleTraining::class);

    $state = $training->review($user, $manifest['dataset_id'], $decision);
    expect($state['payload']['decision_at_ms'])->toBe($decision);
    expect($state['label'])->toBeNull();
    expect($state['label_stats']['counts'])->toBe(['buy' => 0, 'hold' => 0, 'sell' => 0]);
    expect($state['label_stats']['balanced_samples'])->toBe(0);
    expect(max(array_column($state['payload']['series'], 'time')) * 1000)->toBeLessThan($decision);

    $buy = $training->save($user, $manifest['dataset_id'], $decision, 'buy');
    expect($buy->action)->toBe('buy');
    expect($buy->snapshot->verifiedPayload()['vector'])->toBe($state['payload']['vector']);
    $this->assertDatabaseCount('human_candle_labels', 1);
    $stats = $training->review($user, $manifest['dataset_id'], $decision)['label_stats'];
    expect($stats['counts'])->toBe(['buy' => 1, 'hold' => 0, 'sell' => 0]);
    expect($stats['percentages'])->toBe(['buy' => 100.0, 'hold' => 0.0, 'sell' => 0.0]);
    expect($stats['least_represented'])->toBe(['hold', 'sell']);

    $hold = $training->save($user, $manifest['dataset_id'], $decision, 'hold');
    expect($hold->candle_label_id)->toBe($buy->candle_label_id);
    expect($hold->action)->toBe('hold');
    $this->assertDatabaseCount('human_candle_labels', 1);

    $this->actingAs($user)->get(route('human-training.candles.show', [
        'dataset' => $manifest['dataset_id'], 'decision_at_ms' => $decision,
    ]))->assertOk()->assertViewHas('state', fn (array $state): bool => $state['label']->action === 'hold')
        ->assertSee('Unlabelled does not mean HOLD')
        ->assertSee('Left-click')->assertSee('Right-click');

    $training->delete($user, $manifest['dataset_id'], $decision);
    expect(HumanCandleLabel::query()->count())->toBe(0);
    expect($training->review($user, $manifest['dataset_id'], $decision)['label'])->toBeNull();
});

it('supports chart-menu JSON save and delete without a page reload', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $manifest = candleTrainingDataset();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $decision = $rows[0]['decision_at_ms'];

    $this->actingAs($user)->putJson(route('human-training.candles.update', $manifest['dataset_id']), [
        'decision_at_ms' => $decision, 'action' => 'hold',
    ])->assertOk()->assertJson(['decision_at_ms' => $decision, 'action' => 'hold']);
    $this->assertDatabaseHas('human_candle_labels', ['trainer_id' => $user->user_id, 'action' => 'hold']);

    $this->actingAs($user)->deleteJson(route('human-training.candles.destroy', $manifest['dataset_id']), [
        'decision_at_ms' => $decision,
    ])->assertOk()->assertJson(['decision_at_ms' => $decision, 'deleted' => true]);
    $this->assertDatabaseCount('human_candle_labels', 0);
});

it('submits several staged labels when a cached configuration lacks the annotation limit', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id, 'human_training.candle_max_changes' => null]);
    $manifest = candleTrainingDataset(count: 3);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);

    $this->actingAs($user)->postJson(route('human-training.candles.submit', $manifest['dataset_id']), [
        'changes' => array_map(fn (array $row): array => ['decision_at_ms' => $row['decision_at_ms'], 'action' => 'hold'], $rows),
    ])->assertOk()->assertJsonPath('saved', 3)->assertJsonPath('stats.counts.hold', 3);

    $this->assertDatabaseCount('human_candle_labels', 3);
});

it('rejects an oversized annotation request before storing any staged labels', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id, 'human_training.candle_max_changes' => 1]);
    $manifest = candleTrainingDataset(count: 2);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);

    $this->actingAs($user)->postJson(route('human-training.candles.submit', $manifest['dataset_id']), [
        'changes' => array_map(fn (array $row): array => ['decision_at_ms' => $row['decision_at_ms'], 'action' => 'hold'], $rows),
    ])->assertUnprocessable()->assertJsonValidationErrors('changes');

    $this->assertDatabaseCount('human_candle_labels', 0);
    $this->assertDatabaseCount('human_training_snapshots', 0);
});

it('accepts successive submission batches and retries without duplicating labels', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $manifest = candleTrainingDataset();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $this->actingAs($user);

    for ($batch = 0; $batch < 13; $batch++) {
        $this->postJson(route('human-training.candles.submit', $manifest['dataset_id']), [
            'changes' => [['decision_at_ms' => $rows[0]['decision_at_ms'], 'action' => 'hold']],
        ])->assertOk()->assertJsonPath('stats.counts.hold', 1);
    }

    $this->assertDatabaseCount('human_candle_labels', 1);
});

it('validates candle actions and prevents a trainer from changing another trainers label', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $users = User::factory()->count(2)->create();
    config(['operations.owner_uuid' => $users[0]->user_id, 'human_training.trainer_uuids' => [$users[1]->user_id]]);
    $manifest = candleTrainingDataset();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $decision = $rows[0]['decision_at_ms'];
    $training = app(CandleTraining::class);

    $first = $training->save($users[0], $manifest['dataset_id'], $decision, 'buy');
    $second = $training->save($users[1], $manifest['dataset_id'], $decision, 'hold');
    expect($first->snapshot_id)->toBe($second->snapshot_id);
    $this->assertDatabaseCount('human_candle_labels', 2);

    $this->actingAs($users[0])->put(route('human-training.candles.update', $manifest['dataset_id']), [
        'decision_at_ms' => $decision, 'action' => 'super_bull',
    ])->assertSessionHasErrors('action');
    expect($first->fresh()->action)->toBe('buy');
    expect($second->fresh()->action)->toBe('hold');
});

it('saves only the actions permitted by the exact candle direction', function (string $open, string $close, string $action) {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $manifest = candleTrainingDataset($open, $close);
    $decision = IntelligenceFixtures::START + 11 * 60000;

    $this->actingAs($user)->putJson(route('human-training.candles.update', $manifest['dataset_id']), [
        'decision_at_ms' => $decision, 'action' => $action,
    ])->assertOk()->assertJson(['action' => $action]);

    $this->assertDatabaseHas('human_candle_labels', ['trainer_id' => $user->user_id, 'action' => $action]);
})->with([
    'BUY on red' => ['11', '10', 'buy'],
    'SELL on green' => ['10', '11', 'sell'],
    'HOLD on red' => ['11', '10', 'hold'],
    'HOLD on green' => ['10', '11', 'hold'],
    'HOLD on flat' => ['10', '10', 'hold'],
    'precise red' => ['10.000000000000000001', '10', 'buy'],
    'precise green' => ['10', '10.000000000000000001', 'sell'],
]);

it('rejects invalid candle colours with 422 and preserves the existing label', function (string $open, string $close, string $action) {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $manifest = candleTrainingDataset($open, $close);
    $decision = IntelligenceFixtures::START + 11 * 60000;
    app(CandleTraining::class)->save($user, $manifest['dataset_id'], $decision, 'hold');

    $this->actingAs($user)->putJson(route('human-training.candles.update', $manifest['dataset_id']), [
        'decision_at_ms' => $decision, 'action' => $action,
    ])->assertUnprocessable()->assertJsonValidationErrors(['action'])
        ->assertJsonPath('errors.action.0', 'BUY requires a red candle (open > close); SELL requires a green candle (open < close). HOLD is allowed on any candle.');

    $this->assertDatabaseCount('human_candle_labels', 1);
    $this->assertDatabaseHas('human_candle_labels', ['trainer_id' => $user->user_id, 'action' => 'hold']);
})->with([
    'BUY on green' => ['10', '11', 'buy'],
    'SELL on red' => ['11', '10', 'sell'],
    'BUY on flat' => ['10', '10', 'buy'],
    'SELL on flat' => ['10', '10', 'sell'],
]);

it('steps through 50 available rows and clamps replay navigation at both ends', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $manifest = candleTrainingDataset(count: 151);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);

    $this->actingAs($user)->get(route('human-training.candles.show', [
        'dataset' => $manifest['dataset_id'], 'decision_at_ms' => $rows[75]['decision_at_ms'],
    ]))->assertOk()->assertViewHas('state', fn (array $state): bool => $state['previous_decision_at_ms'] === $rows[25]['decision_at_ms']
        && $state['next_decision_at_ms'] === $rows[125]['decision_at_ms']
        && $state['earliest_window_decision_at_ms'] === $rows[89]['decision_at_ms'])
        ->assertViewHas('datasets', fn (array $datasets): bool => $datasets[0]['dataset_id'] === $manifest['dataset_id'])
        ->assertSee('Earliest available data:')->assertDontSee('data-current-action', false);

    $training = app(CandleTraining::class);
    expect($training->review($user, $manifest['dataset_id'], $rows[20]['decision_at_ms'])['previous_decision_at_ms'])->toBe($rows[0]['decision_at_ms']);
    expect($training->review($user, $manifest['dataset_id'], $rows[141]['decision_at_ms'])['next_decision_at_ms'])->toBe($rows[150]['decision_at_ms']);
    expect($training->review($user, $manifest['dataset_id'], $rows[0]['decision_at_ms'])['previous_decision_at_ms'])->toBeNull();
    expect($training->review($user, $manifest['dataset_id'], $rows[150]['decision_at_ms'])['next_decision_at_ms'])->toBeNull();
});

it('loads older history with this trainers saved labels and stops at the earliest candle', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $users = User::factory()->count(2)->create();
    config(['operations.owner_uuid' => $users[0]->user_id, 'human_training.trainer_uuids' => [$users[1]->user_id]]);
    $manifest = candleTrainingDataset(count: 151);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $training = app(CandleTraining::class);
    $training->save($users[0], $manifest['dataset_id'], $rows[30]['decision_at_ms'], 'buy');
    $training->save($users[1], $manifest['dataset_id'], $rows[31]['decision_at_ms'], 'hold');
    $url = route('human-training.candles.history', $manifest['dataset_id']);
    $query = ['decision_at_ms' => $rows[150]['decision_at_ms'], 'before_ms' => $rows[61]['microtimestamp']];

    $page = $this->actingAs($users[0])->getJson($url.'?'.http_build_query($query))
        ->assertOk()->assertJsonCount(50, 'series')->assertJsonPath('has_more', true)
        ->assertJsonPath('labels', [['time' => intdiv($rows[30]['microtimestamp'], 1000), 'action' => 'buy']])
        ->assertJsonPath('series.0.time', intdiv($rows[11]['microtimestamp'], 1000))
        ->assertJsonPath('series.49.time', intdiv($rows[60]['microtimestamp'], 1000))
        ->assertJsonPath('allowed_actions.'.intdiv($rows[30]['microtimestamp'], 1000), ['buy', 'hold'])
        ->assertJsonMissingPath('vector')->assertJsonMissingPath('patterns')->json();

    $query['before_ms'] = $page['series'][0]['time'] * 1000;
    $this->getJson($url.'?'.http_build_query($query))->assertOk()
        ->assertJsonCount(11, 'series')->assertJsonPath('has_more', false)
        ->assertJsonPath('series.0.time', intdiv($rows[0]['microtimestamp'], 1000));
    $query['before_ms'] = $rows[0]['microtimestamp'];
    $this->getJson($url.'?'.http_build_query($query))->assertOk()
        ->assertJsonCount(0, 'series')->assertJsonPath('has_more', false);

    $query['before_ms'] = $rows[150]['decision_at_ms'];
    $this->getJson($url.'?'.http_build_query($query))->assertUnprocessable()->assertJsonValidationErrors(['before_ms']);
});

it('protects historical candle data from unauthenticated and unauthorized requests', function () {
    $dataset = (string) Str::uuid7();
    $url = route('human-training.candles.history', $dataset).'?decision_at_ms=1000&before_ms=500';

    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
});

it('opens the first configured chart window when following the earliest data link', function (int $window, int $count, int $visible) {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id, 'human_training.chart_candles' => $window]);
    $manifest = candleTrainingDataset(count: $count);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $training = app(CandleTraining::class);
    $training->save($user, $manifest['dataset_id'], $rows[0]['decision_at_ms'], 'buy');
    $response = $this->actingAs($user)->get(route('human-training.candles.show', [
        'dataset' => $manifest['dataset_id'], 'decision_at_ms' => $rows[$count - 1]['decision_at_ms'],
    ]));
    preg_match('/Earliest available data: <a href="([^"]+)"/', $response->getContent(), $link);
    expect($link)->toHaveCount(2);

    $this->get(html_entity_decode($link[1]))->assertOk()
        ->assertViewHas('state', function (array $state) use ($rows, $visible): bool {
            return count($state['payload']['series']) === $visible
                && $state['payload']['series'][0]['time'] === intdiv($rows[0]['microtimestamp'], 1000)
                && $state['payload']['series'][$visible - 1]['time'] === intdiv($rows[$visible - 1]['microtimestamp'], 1000)
                && $state['payload']['decision_at_ms'] === $rows[$visible - 1]['decision_at_ms']
                && $state['has_more'] === false
                && $state['visible_labels'] === [['time' => intdiv($rows[0]['microtimestamp'], 1000), 'action' => 'buy']];
        });
})->with([
    'default 90-candle window' => [90, 151, 90],
    'fewer candles than the window' => [90, 12, 12],
    'custom chart window' => [30, 151, 30],
    'single-candle dataset' => [90, 1, 1],
]);

it('loads newer candles and saved labels up to the selected dataset boundary', function (int $window, int $pageSize) {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $users = User::factory()->count(2)->create();
    config(['operations.owner_uuid' => $users[0]->user_id, 'human_training.trainer_uuids' => [$users[1]->user_id],
        'human_training.chart_candles' => $window]);
    $manifest = candleTrainingDataset(count: 151);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $training = app(CandleTraining::class);
    $training->save($users[0], $manifest['dataset_id'], $rows[110]['decision_at_ms'], 'buy');
    $training->save($users[1], $manifest['dataset_id'], $rows[111]['decision_at_ms'], 'hold');
    $url = route('human-training.candles.history', $manifest['dataset_id']);
    $query = ['decision_at_ms' => $rows[89]['decision_at_ms'], 'after_ms' => $rows[89]['microtimestamp']];

    $page = $this->actingAs($users[0])->getJson($url.'?'.http_build_query($query))
        ->assertOk()->assertJsonCount($pageSize, 'series')->assertJsonPath('has_more', true)
        ->assertJsonPath('series.0.time', intdiv($rows[90]['microtimestamp'], 1000))
        ->assertJsonPath('series.'.($pageSize - 1).'.time', intdiv($rows[89 + $pageSize]['microtimestamp'], 1000))
        ->assertJsonPath('decision_at_ms', $rows[89 + $pageSize]['decision_at_ms'])
        ->assertJsonPath('labels', [['time' => intdiv($rows[110]['microtimestamp'], 1000), 'action' => 'buy']])
        ->assertJsonMissingPath('vector')->assertJsonMissingPath('patterns')->json();

    $loaded = $page['series'];
    while ($page['has_more']) {
        $query = ['decision_at_ms' => $page['decision_at_ms'], 'after_ms' => $page['series'][array_key_last($page['series'])]['time'] * 1000];
        $page = $this->getJson($url.'?'.http_build_query($query))->assertOk()->json();
        $loaded = [...$loaded, ...$page['series']];
    }
    expect(array_column($loaded, 'time'))->toBe(array_map(fn (array $row): int => intdiv($row['microtimestamp'], 1000), array_slice($rows, 90)));
    expect($page['decision_at_ms'])->toBe($rows[150]['decision_at_ms']);
    expect($page['next_decision_at_ms'])->toBeNull();
    $query = ['decision_at_ms' => $rows[150]['decision_at_ms'], 'after_ms' => $rows[150]['microtimestamp']];
    $this->getJson($url.'?'.http_build_query($query))->assertOk()->assertJsonCount(0, 'series')->assertJsonPath('has_more', false);
    $query['after_ms'] += 60000;
    $this->getJson($url.'?'.http_build_query($query))->assertUnprocessable()
        ->assertJsonPath('errors.after_ms.0', 'Continue from the last loaded replay candle.');
})->with(['default window' => [90, 50], 'smaller configured window' => [30, 30]]);

it('rejects ambiguous forward and backward history cursors', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $manifest = candleTrainingDataset();

    $this->actingAs($user)->getJson(route('human-training.candles.history', $manifest['dataset_id']).'?'.http_build_query([
        'decision_at_ms' => IntelligenceFixtures::START + 11 * 60000,
        'before_ms' => IntelligenceFixtures::START + 10 * 60000,
        'after_ms' => IntelligenceFixtures::START + 10 * 60000,
    ]))->assertUnprocessable()->assertJsonValidationErrors(['before_ms', 'after_ms']);
});
it('preselects the dashboard market in both Human Training selectors', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $manifest = candleTrainingDataset(count: 2);
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => $manifest['exchange'], 'config' => '{}']);
    $market = Market::query()->create([
        'exchange_id' => $exchange->exchange_id, 'symbol' => $manifest['symbol'], 'tick_size' => '0.01',
    ]);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => $manifest['period']]);
    MarketSubscription::query()->create([
        'user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true,
    ]);

    $response = $this->actingAs($user)->get(route('human-training.index', [
        'exchange' => $manifest['exchange'], 'symbol' => $manifest['symbol'], 'period' => $manifest['period'],
    ]))->assertOk()->assertViewHas('selectedDataset', $manifest['dataset_id']);

    expect(substr_count($response->getContent(), 'value="'.$manifest['dataset_id'].'" selected'))->toBe(2);
});
