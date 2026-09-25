<?php

use App\Models\Exchange;
use App\Models\Ticker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

it('adds a supported exchange and refuses to duplicate a seeded class', function () {
    $arguments = ['action' => 'add', 'class' => 'kraken'];

    expect(Artisan::call('trademinator:exchange', $arguments))->toBe(0);
    $exchange = Exchange::query()->where('class', 'kraken')->firstOrFail();
    expect($exchange->name)->toBe('kraken')
        ->and($exchange->config)->toBe('{}')
        ->and(Str::isUuid((string) $exchange->exchange_id))->toBeTrue();

    expect(Artisan::call('trademinator:exchange', $arguments))->toBe(1)
        ->and(Exchange::query()->where('class', 'kraken')->count())->toBe(1);
});

it('edits a seeded exchange without changing its UUID or unrelated settings', function () {
    $exchange = Exchange::query()->create([
        'class' => 'kraken', 'name' => 'kraken', 'config' => '{"timeout":20000}',
    ]);

    expect(Artisan::call('trademinator:exchange', [
        'action' => 'edit', 'class' => 'kraken', '--name' => 'Kraken Public',
    ]))->toBe(0);
    expect($exchange->fresh()->name)->toBe('Kraken Public')
        ->and($exchange->fresh()->config)->toBe('{"timeout":20000}')
        ->and($exchange->fresh()->exchange_id)->toBe($exchange->exchange_id);

    expect(Artisan::call('trademinator:exchange', [
        'action' => 'edit', 'class' => 'kraken', '--config' => '{"timeout":30000}',
    ]))->toBe(0);
    expect($exchange->fresh()->config)->toBe('{"timeout":30000}')
        ->and($exchange->fresh()->name)->toBe('Kraken Public');
});

it('refuses an ambiguous CCXT ID rather than editing an arbitrary duplicate', function () {
    Exchange::query()->create(['class' => 'kraken', 'name' => 'First', 'config' => '{}']);
    Exchange::query()->create(['class' => 'kraken', 'name' => 'Second', 'config' => '{}']);

    expect(Artisan::call('trademinator:exchange', [
        'action' => 'edit', 'class' => 'kraken', '--name' => 'Changed',
    ]))->toBe(1)
        ->and(Exchange::query()->where('name', 'Changed')->exists())->toBeFalse();
});

it('accepts a config file and rejects unsupported exchanges or malformed settings', function () {
    $path = tempnam(sys_get_temp_dir(), 'trademinator-ccxt-');
    try {
        file_put_contents($path, '{"timeout":30000}');
        expect(Artisan::call('trademinator:exchange', [
            'action' => 'add', 'class' => 'kraken', '--config-file' => $path,
        ]))->toBe(0)
            ->and(Exchange::query()->where('class', 'kraken')->firstOrFail()->config)->toBe('{"timeout":30000}');
    } finally {
        unlink($path);
    }

    expect(Artisan::call('trademinator:exchange', [
        'action' => 'add', 'class' => 'not_a_ccxt_exchange',
    ]))->toBe(1)
        ->and(Artisan::call('trademinator:exchange', [
            'action' => 'edit', 'class' => 'kraken', '--config' => '[]',
        ]))->toBe(1)
        ->and(Exchange::query()->where('class', 'kraken')->firstOrFail()->config)->toBe('{"timeout":30000}');
});

it('requires confirmation to delete and leaves historical candles untouched', function () {
    Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    Ticker::query()->create([
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'microtimestamp' => 1_700_000_000_000,
        'payload' => '{"microtimestamp":1700000000000,"close":"100"}',
    ]);

    $this->artisan('trademinator:exchange', ['action' => 'delete', 'class' => 'kraken'])
        ->expectsConfirmation('Delete exchange kraken configuration? Historical candles will remain.', 'no')
        ->assertExitCode(1);

    expect(Exchange::query()->where('class', 'kraken')->exists())->toBeTrue()
        ->and(Artisan::call('trademinator:exchange', [
            'action' => 'delete', 'class' => 'kraken', '--force' => true,
        ]))->toBe(0)
        ->and(Exchange::query()->where('class', 'kraken')->exists())->toBeFalse()
        ->and(Ticker::query()->where('exchange', 'kraken')->count())->toBe(1);
});

it('filters a seeded exchange list without displaying stored config', function () {
    Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{"token":"private-value"}']);
    Exchange::query()->create(['class' => 'coinbase', 'name' => 'Coinbase', 'config' => '{}']);

    expect(Artisan::call('trademinator:exchange', ['action' => 'list', '--search' => 'krak']))->toBe(0)
        ->and(Artisan::output())->toContain('kraken')
        ->not->toContain('coinbase')
        ->not->toContain('private-value');
});
