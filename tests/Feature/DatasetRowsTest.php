<?php

use App\Domain\Intelligence\HumanTraining;
use App\Domain\Research\DatasetStore;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    config(['research.path' => sys_get_temp_dir().'/dataset-rows-'.Str::uuid7()]);
});

afterEach(function () {
    File::deleteDirectory(config('research.path'));
});

function saveIndexedDatasetManifest(array $manifest): void
{
    $json = json_encode($manifest, JSON_THROW_ON_ERROR);
    file_put_contents(app(DatasetStore::class)->directory($manifest['dataset_id']).'/manifest.json', $json);
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])->update(['manifest' => $json]);
}

it('reads exact rows and navigation boundaries without filling missing candles', function () {
    $store = app(DatasetStore::class);
    $manifest = IntelligenceFixtures::snapshot(4);
    [, $expected] = $store->load($manifest['dataset_id']);
    unset($expected[1]);
    $expected = array_values($expected);
    $bytes = implode('', array_map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR)."\n", $expected));
    $manifest['rows'] = 3;
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    file_put_contents($store->directory($manifest['dataset_id']).'/rows.jsonl', $bytes);
    saveIndexedDatasetManifest($manifest);

    [$actual, $rows] = $store->open($manifest['dataset_id']);

    expect($actual)->toEqual($manifest);
    expect(count($rows))->toBe(3);
    expect($rows->at(0))->toBe($expected[0]);
    expect($rows->at(2))->toBe($expected[2]);
    expect($rows->indexOfDecision(IntelligenceFixtures::START + 180000))->toBe(1);
    expect($rows->findDecision(IntelligenceFixtures::START + 120000))->toBeNull();
    expect($rows->findDecision(IntelligenceFixtures::START + 240000))->toBe($expected[2]);
    expect($rows->slice(1, 50))->toBe([$expected[1], $expected[2]]);
    expect($rows->slice(3, 50))->toBe([]);
    expect($rows->before(IntelligenceFixtures::START, IntelligenceFixtures::START + 240000))->toBeNull();
    expect($rows->before(IntelligenceFixtures::START + 120000, IntelligenceFixtures::START + 240000))->toBe($expected[0]);
    expect($rows->before(IntelligenceFixtures::START + 240000, IntelligenceFixtures::START + 180000))->toBe($expected[1]);
    expect($rows->forTimestamps([IntelligenceFixtures::START, IntelligenceFixtures::START + 60000]))
        ->toBe([IntelligenceFixtures::START => $expected[0]]);
    expect(iterator_to_array($rows))->toBe($expected);
    expect(fn () => $rows->at(-1))->toThrow(OutOfBoundsException::class);
    expect(fn () => $rows->at(3))->toThrow(OutOfBoundsException::class);
});

it('verifies the unrequested tail before returning an indexed dataset', function (string $damage, string $exception, string $message) {
    $store = app(DatasetStore::class);
    $manifest = IntelligenceFixtures::snapshot(3);
    $path = $store->directory($manifest['dataset_id']).'/rows.jsonl';
    $lines = file($path);
    $last = json_decode($lines[2], true, flags: JSON_THROW_ON_ERROR);
    if ($damage === 'checksum') {
        $last['label'] = 'buy';
    } elseif ($damage === 'order') {
        $last['decision_at_ms'] -= 60000;
    } elseif ($damage === 'vector') {
        $last['vector'] = [];
    } elseif ($damage === 'horizon') {
        $last['label_available_at_ms'] = $last['decision_at_ms'];
    }
    $lines[2] = $damage === 'json' ? "{broken\n" : json_encode($last, JSON_THROW_ON_ERROR)."\n";
    if ($damage === 'count') {
        array_pop($lines);
    }
    file_put_contents($path, implode('', $lines));

    expect(fn () => $store->open($manifest['dataset_id']))->toThrow($exception, $message);
})->with([
    ['checksum', RuntimeException::class, 'checksum'],
    ['count', RuntimeException::class, 'row count'],
    ['order', RuntimeException::class, 'row contract'],
    ['vector', RuntimeException::class, 'row contract'],
    ['horizon', RuntimeException::class, 'row contract'],
    ['json', JsonException::class, 'Syntax error'],
]);

it('rejects row changes between verification and a later seek', function () {
    $store = app(DatasetStore::class);
    $manifest = IntelligenceFixtures::snapshot(3);
    [, $rows] = $store->open($manifest['dataset_id']);
    $path = $store->directory($manifest['dataset_id']).'/rows.jsonl';
    file_put_contents($path, str_replace('sell', 'hold', file_get_contents($path)));

    expect(fn () => $rows->at(2))->toThrow(RuntimeException::class, 'checksum');
});

it('retains semantic history beyond the research cap and enforces explicit caller limits', function () {
    $manifest = IntelligenceFixtures::snapshot(3);
    config(['research.max_rows' => 1]);
    $store = app(DatasetStore::class);

    expect(count($store->open($manifest['dataset_id'])[1]))->toBe(3);
    expect(fn () => $store->open($manifest['dataset_id'], 2))->toThrow(RuntimeException::class, 'max_rows');
});

it('reports missing rows and supports an empty artifact without invalid seeks', function () {
    $manifest = IntelligenceFixtures::snapshot(0);
    $store = app(DatasetStore::class);
    [, $rows] = $store->open($manifest['dataset_id']);

    expect(count($rows))->toBe(0);
    expect($rows->findDecision(IntelligenceFixtures::START))->toBeNull();
    expect(iterator_to_array($rows))->toBe([]);
    unset($rows);
    unlink($store->directory($manifest['dataset_id']).'/rows.jsonl');
    expect(fn () => $store->open($manifest['dataset_id']))->toThrow(RuntimeException::class, 'missing');
});

/** Build wide, valid rows incrementally so fixture setup does not mask request memory use. */
function largeIndexedTrainingDataset(): array
{
    $manifest = IntelligenceFixtures::snapshot(0);
    $manifest['rows'] = 12000;
    $manifest['keys'] = array_map(fn (int $key): string => 'fixture.'.$key, range(0, 511));
    $manifest['as_of_ms'] = IntelligenceFixtures::START + 12003 * 60000;
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']).'/rows.jsonl';
    $file = fopen($path, 'wb');
    $hash = hash_init('sha256');
    $candle = ['open' => '10', 'high' => '11', 'low' => '9', 'close' => '10', 'volume' => '1'];
    $batch = [];
    try {
        for ($index = -1; $index < 12000; $index++) {
            $timestamp = IntelligenceFixtures::START + $index * 60000;
            if ($index >= 0) {
                $row = ['microtimestamp' => $timestamp, 'decision_at_ms' => $timestamp + 60000,
                    'label_available_at_ms' => $timestamp + 180000, 'vector' => array_fill(0, 512, 0.5),
                    'label' => 'hodl', 'candle' => $candle, 'patterns' => []];
                $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
                DatasetStore::write($file, $line);
                hash_update($hash, $line);
            }
            $batch[] = ['ticker_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
                'microtimestamp' => $timestamp, 'payload' => json_encode($candle, JSON_THROW_ON_ERROR)];
            if (count($batch) === 100) {
                DB::table('tickers')->insert($batch);
                $batch = [];
            }
        }
        DB::table('tickers')->insert($batch);
    } finally {
        fclose($file);
    }
    $manifest['rows_sha256'] = hash_final($hash);
    saveIndexedDatasetManifest($manifest);

    return $manifest;
}

it('opens trains navigates and submits a large dataset within a bounded request memory budget', function () {
    $this->travelTo('2024-02-01 00:00:00 UTC');
    Http::preventStrayRequests();
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id, 'human_training.enabled' => true,
        'human_training.candle_enabled' => true, 'human_training.trend_enabled' => true]);
    $manifest = largeIndexedTrainingDataset();
    $dataset = $manifest['dataset_id'];
    $decision = IntelligenceFixtures::START + 6001 * 60000;
    $originalLimit = ini_get('memory_limit');
    gc_collect_cycles();
    $baseline = memory_get_usage(true);
    $budget = 32 * 1024 * 1024;
    // Use 128 MB in isolation; leave room for fixtures retained by the full suite.
    ini_set('memory_limit', (string) max(128 * 1024 * 1024, $baseline + $budget));
    memory_reset_peak_usage();
    try {
        $this->actingAs($user)->post(route('human-training.candles.start'), ['dataset' => $dataset])->assertRedirect();
        $this->get(route('human-training.candles.show', ['dataset' => $dataset, 'decision_at_ms' => $decision]))
            ->assertOk()->assertViewHas('state', fn (array $state): bool => $state['payload']['decision_at_ms'] === $decision
                && $state['previous_decision_at_ms'] === $decision - 50 * 60000
                && $state['next_decision_at_ms'] === $decision + 50 * 60000);
        $this->getJson(route('human-training.candles.history', ['dataset' => $dataset,
            'decision_at_ms' => $decision, 'after_ms' => $decision - 60000]))
            ->assertOk()->assertJsonPath('decision_at_ms', $decision + 50 * 60000)->assertJsonCount(50, 'series');
        $this->getJson(route('human-training.candles.history', ['dataset' => $dataset,
            'decision_at_ms' => $decision, 'before_ms' => $decision - 90 * 60000]))
            ->assertOk()->assertJsonCount(50, 'series');
        $this->postJson(route('human-training.candles.submit', $dataset), [
            'changes' => [['decision_at_ms' => $decision, 'action' => 'hold']],
        ])->assertOk()->assertJsonPath('saved', 1)->assertJsonPath('stats.counts.hold', 1);
        $review = app(HumanTraining::class)->assign($user, $dataset);
        expect($review->submitted_at)->toBeNull();
        expect(memory_get_peak_usage(true) - $baseline)->toBeLessThan($budget);
        $this->assertDatabaseCount('human_candle_labels', 1);
        Http::assertNothingSent();
    } finally {
        ini_set('memory_limit', $originalLimit);
    }
});
