<?php

use App\Domain\Intelligence\CandleTraining;
use App\Domain\Research\DatasetStore;
use App\Models\HumanCandleLabel;
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

function candleTrainingDataset(): array
{
    $manifest = IntelligenceFixtures::snapshot(20, patterns: true);
    for ($i = 0; $i < 23; $i++) {
        IntelligenceFixtures::feature($i, ($i % 3) / 2);
    }
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $row = $rows[10];
    $row['candle'] = ['open' => '10', 'close' => '10', 'high' => '11', 'low' => '9', 'volume' => '1'];
    $bytes = json_encode($row)."\n";
    $manifest['rows'] = 1;
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

it('creates editable BUY HOLD SELL labels and removes them back to unlabelled', function () {
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
    expect(max(array_column($state['payload']['series'], 'time')) * 1000)->toBeLessThan($decision);

    $buy = $training->save($user, $manifest['dataset_id'], $decision, 'buy');
    expect($buy->action)->toBe('buy');
    expect($buy->snapshot->verifiedPayload()['vector'])->toBe($state['payload']['vector']);
    $this->assertDatabaseCount('human_candle_labels', 1);

    $sell = $training->save($user, $manifest['dataset_id'], $decision, 'sell');
    expect($sell->candle_label_id)->toBe($buy->candle_label_id);
    expect($sell->action)->toBe('sell');
    $this->assertDatabaseCount('human_candle_labels', 1);

    $this->actingAs($user)->get(route('human-training.candles.show', [
        'dataset' => $manifest['dataset_id'], 'decision_at_ms' => $decision,
    ]))->assertOk()->assertSee('Current label: SELL')->assertSee('Unlabelled does not mean HOLD');

    $training->delete($user, $manifest['dataset_id'], $decision);
    expect(HumanCandleLabel::query()->count())->toBe(0);
    expect($training->review($user, $manifest['dataset_id'], $decision)['label'])->toBeNull();
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
