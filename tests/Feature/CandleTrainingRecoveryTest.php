<?php

use App\Domain\Intelligence\CandleTrainingRecovery;
use App\Domain\Research\DatasetStore;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config([
        'research.path' => sys_get_temp_dir().'/candle-training-recovery-'.Str::uuid7(),
        'human_training.enabled' => true,
        'human_training.trainer_uuids' => [],
        'operations.owner_uuid' => null,
        'operations.owner_uuids' => [],
        'intelligence.schema' => 'core',
    ]);
});

afterEach(function () {
    File::deleteDirectory(config('research.path'));
});

function candleRecoveryTrainer(string $role): User
{
    $user = User::factory()->create();
    config([
        'operations.owner_uuid' => $role === 'owner' ? $user->user_id : null,
        'operations.owner_uuids' => $role === 'additional-owner' ? [$user->user_id] : [],
        'human_training.trainer_uuids' => $role === 'trainer' ? [$user->user_id] : [],
    ]);

    return $user;
}

/** A valid frozen dataset whose canonical source history is no longer present. */
function candleRecoveryDataset(): array
{
    $manifest = IntelligenceFixtures::snapshot(60, patterns: true);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    foreach ($rows as &$row) {
        $row['candle'] = ['open' => '11', 'high' => '12', 'low' => '9', 'close' => '10', 'volume' => '1'];
    }
    unset($row);
    $bytes = implode('', array_map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR)."\n", $rows));
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']);
    file_put_contents($path.'/rows.jsonl', $bytes);
    file_put_contents($path.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])
        ->update(['manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)]);

    return [$manifest, $rows];
}

it('shows role-aware recovery on both history directions without weakening the 422 guard', function (string $role, string $direction) {
    $user = candleRecoveryTrainer($role);
    [$manifest, $rows] = candleRecoveryDataset();
    $replay = $rows[$direction === 'older' ? 10 : 0];
    $field = $direction === 'older' ? 'before_ms' : 'after_ms';
    $response = $this->actingAs($user)->getJson(route('human-training.candles.history', [
        'dataset' => $manifest['dataset_id'],
        'decision_at_ms' => $replay['decision_at_ms'],
        $field => $replay['microtimestamp'],
        // Submitted flags must never grant access to owner-only guidance.
        'is_owner' => true,
    ]))->assertUnprocessable()->assertJsonValidationErrors([$field]);

    $message = $response->json('message');
    expect($response->json('errors.'.$field.'.0'))->toBe($message);
    expect($message)->toContain('This history no longer matches the frozen dataset.');
    if ($role === 'trainer') {
        expect($message)->toContain('contact the server owner');
        expect($response->getContent())->not->toContain('php artisan');
        expect($response->getContent())->not->toContain('trademinator:');
    } else {
        expect($message)->toContain("php artisan trademinator:build-features 'kraken' 'BTC/USD' '1m' &&\n");
        expect($message)->toContain("php artisan trademinator:knn-build 'kraken' 'BTC/USD' '1m' --schema='core'");
        expect($message)->toContain('select the new dataset');
        expect($message)->not->toContain('--dataset=');
    }
    $this->assertDatabaseCount('human_candle_labels', 0);
})->with(['owner', 'additional-owner', 'trainer'])->with(['older', 'newer']);

it('uses the same recovery for review and submission source mismatches', function (string $role, string $operation) {
    $user = candleRecoveryTrainer($role);
    [$manifest, $rows] = candleRecoveryDataset();
    if ($operation === 'review') {
        $field = 'decision_at_ms';
        $response = $this->actingAs($user)->getJson(route('human-training.candles.show', [
            'dataset' => $manifest['dataset_id'], 'decision_at_ms' => $rows[0]['decision_at_ms'],
        ]));
    } else {
        $field = 'changes';
        $response = $this->actingAs($user)->postJson(route('human-training.candles.submit', $manifest['dataset_id']), [
            'changes' => [['decision_at_ms' => $rows[0]['decision_at_ms'], 'action' => 'hold']],
        ]);
    }
    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect($response->json('errors.'.$field.'.0'))->toBe($response->json('message'));
    if ($role === 'owner') {
        expect($response->json('message'))->toContain('php artisan trademinator:knn-build');
    } else {
        expect($response->json('message'))->toContain('contact the server owner');
        expect($response->getContent())->not->toContain('trademinator:');
    }
    $this->assertDatabaseCount('human_candle_labels', 0);
})->with(['owner', 'trainer'])->with(['review', 'submit']);

it('does not show rebuild guidance for an invalid history cursor', function () {
    $user = candleRecoveryTrainer('owner');
    [$manifest, $rows] = candleRecoveryDataset();
    $this->actingAs($user)->getJson(route('human-training.candles.history', [
        'dataset' => $manifest['dataset_id'], 'decision_at_ms' => $rows[0]['decision_at_ms'],
        'after_ms' => $rows[0]['microtimestamp'] + 1,
    ]))->assertUnprocessable()
        ->assertJsonPath('errors.after_ms.0', 'Continue from the last loaded replay candle.');
});

it('still rejects users without training permission', function () {
    $user = candleRecoveryTrainer('unauthorized');
    [$manifest, $rows] = candleRecoveryDataset();
    $this->actingAs($user)->getJson(route('human-training.candles.history', [
        'dataset' => $manifest['dataset_id'], 'decision_at_ms' => $rows[0]['decision_at_ms'],
        'after_ms' => $rows[0]['microtimestamp'],
    ]))->assertForbidden();
});

it('preserves a supported dataset schema in the owner command', function (string $schema) {
    $user = candleRecoveryTrainer('owner');
    $error = CandleTrainingRecovery::exception($user, [
        'exchange' => 'ndax', 'symbol' => 'XRP/CAD', 'period' => '5m', 'schema' => $schema,
    ], 'after_ms');
    expect($error->errors()['after_ms'][0])
        ->toContain("php artisan trademinator:knn-build 'ndax' 'XRP/CAD' '5m' --schema='{$schema}'");
})->with(['core', 'technical', 'full']);

it('uses an explicit supported fallback for a custom dataset schema', function () {
    $user = candleRecoveryTrainer('owner');
    config(['intelligence.schema' => 'technical']);
    $error = CandleTrainingRecovery::exception($user, [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m', 'schema' => 'custom',
    ], 'after_ms');
    expect($error->errors()['after_ms'][0])->toContain('replacement will use the technical schema');
    expect($error->errors()['after_ms'][0])->toContain("--schema='technical'")->not->toContain("--schema='custom'");
});

it('shell-quotes manifest values in the owner command', function () {
    $user = candleRecoveryTrainer('owner');
    $symbol = "TEST'$(printf unsafe)/USD";
    $error = CandleTrainingRecovery::exception($user, [
        'exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1m', 'schema' => 'core',
    ], 'after_ms');
    expect($error->errors()['after_ms'][0])->toContain(escapeshellarg($symbol));
});
