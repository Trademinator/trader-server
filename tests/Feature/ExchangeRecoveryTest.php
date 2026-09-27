<?php

use App\Domain\MarketData\MarketCatalog;
use App\Models\Exchange;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Support\Facades\Cache;

it('restores missing exchanges without changing users or existing exchange settings and clears an empty catalogue cache', function () {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'My Kraken', 'config' => '{"timeout":43210}']);
    $original = $exchange->fresh()->getAttributes();
    $userBefore = $user->fresh()->getAttributes();
    Cache::put(MarketCatalog::EXCHANGES_CACHE, [], 3600);

    $this->artisan('db:seed', ['--class' => ExchangeSeeder::class, '--force' => true])->assertSuccessful();
    expect(Exchange::query()->where('class', 'bitso')->exists())->toBeTrue()
        ->and($exchange->fresh()->getAttributes())->toBe($original)
        ->and($user->fresh()->getAttributes())->toBe($userBefore)
        ->and(User::query()->count())->toBe(1)
        ->and(Cache::has(MarketCatalog::EXCHANGES_CACHE))->toBeFalse();
    $count = Exchange::query()->count();
    Cache::put(MarketCatalog::EXCHANGES_CACHE, [], 3600);
    $this->artisan('db:seed', ['--class' => ExchangeSeeder::class, '--force' => true])->assertSuccessful();
    expect(Exchange::query()->count())->toBe($count)
        ->and(Cache::has(MarketCatalog::EXCHANGES_CACHE))->toBeFalse();
});

it('explains an empty catalogue and displays restored exchanges on the markets page', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('markets.index'))->assertOk()
        ->assertSee('No exchanges are available yet.');
    expect(Cache::get(MarketCatalog::EXCHANGES_CACHE)['choices'])->toBe([]);
    $this->artisan('db:seed', ['--class' => ExchangeSeeder::class, '--force' => true])->assertSuccessful();
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldNotReceive('setExchange');
    $repository->shouldNotReceive('describe');
    app()->instance(ExchangeRepository::class, $repository);
    $this->get(route('markets.index'))->assertOk()->assertDontSee('No exchanges are available yet.')
        ->assertSee('<option value="kraken"', false);
    expect($user->fresh())->not->toBeNull();
});
