<?php

use App\Enums\TradeAction;
use App\Models\Knowledge;
use App\Models\Ticker;
use App\Models\User;
use App\Repositories\TickerRepository;
use Illuminate\Support\Str;

it('creates UUID string primary keys from the model trait', function () {
    $user = User::factory()->create();

    expect($user->getKeyType())->toBe('string')
        ->and(Str::isUuid((string) $user->getKey()))->toBeTrue();
});

it('upserts ticker payloads by their natural key without replacing the UUID primary key', function () {
    $repository = new TickerRepository;
    $candle = [
        'human_date' => '2023-11-14 22:13:20',
        'microtimestamp' => 1_700_000_000_000,
        'open' => '100.00000000',
        'high' => '103.00000000',
        'low' => '99.00000000',
        'close' => '102.00000000',
        'volume' => '12.00000000',
    ];

    $repository->saveTickers('kraken', 'BTC/USD', '1m', [$candle]);
    $first = Ticker::query()->firstOrFail();
    $firstId = $first->ticker_id;

    $candle['close'] = '102.50000000';
    $repository->saveTickers('kraken', 'BTC/USD', '1m', [$candle]);

    $second = Ticker::query()->firstOrFail();
    $payload = json_decode($second->payload, true, flags: JSON_THROW_ON_ERROR);

    expect(Ticker::query()->count())->toBe(1)
        ->and(Str::isUuid($firstId))->toBeTrue()
        ->and($second->ticker_id)->toBe($firstId)
        ->and($payload['close'])->toBe('102.50000000');
});

it('persists trade action enum values in the knowledge table', function () {
    $knowledge = Knowledge::query()->create([
        'microtimestamp' => 1_700_000_000_000,
        'exchange' => 'kraken',
        'symbol' => 'BTC/USD',
        'period' => '1m',
        'action' => TradeAction::BUY->value,
        'value' => '100.00000000',
        'learned' => false,
    ]);

    expect(Str::isUuid((string) $knowledge->knowledge_id))->toBeTrue()
        ->and($knowledge->action)->toBe('buy');
});
