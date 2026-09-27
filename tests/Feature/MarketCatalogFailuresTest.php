<?php

use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketCatalog;
use App\Domain\MarketData\MarketCatalogException;
use App\Models\Exchange;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use ccxt\AuthenticationError;
use ccxt\DDoSProtection;
use ccxt\ExchangeNotAvailable;
use ccxt\NotSupported;
use ccxt\PermissionDenied;
use ccxt\RateLimitExceeded;
use ccxt\RequestTimeout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

it('excludes ApeX from spot choices and explains its unsupported market type on a direct request', function () {
    Exchange::query()->create(['class' => 'apex', 'name' => 'Apex', 'config' => '{}']);
    $this->actingAs(User::factory()->create())->get(route('markets.index'))
        ->assertOk()->assertDontSee('<option value="apex"', false);
    $this->getJson(route('markets.options', 'apex'))->assertStatus(422)
        ->assertJsonPath('code', 'spot_unsupported')->assertJsonStructure(['message', 'reference']);
});

it('reports Alpaca missing credentials without claiming its pair catalogue is empty', function () {
    Exchange::query()->create(['class' => 'alpaca', 'name' => 'Alpaca', 'config' => '{}']);
    $this->actingAs(User::factory()->create())->getJson(route('markets.options', 'alpaca'))
        ->assertStatus(422)->assertJsonPath('code', 'authentication_required')
        ->assertJsonMissingPath('symbols');
});

it('returns safe actionable failures and keeps them out of the successful catalogue cache', function (string $class, string $code, int $status) {
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    Log::spy();
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->twice();
    $repository->shouldReceive('describe')->twice()->andReturn(['timeframes' => ['1m' => '1m'], 'precisionMode' => \ccxt\TICK_SIZE]);
    $exception = new $class('SECRET-KEY signed-url?secret=do-not-log');
    $repository->shouldReceive('spotMarkets')->once()->andThrow($exception);
    $repository->shouldReceive('spotMarkets')->once()->andReturn(['BTC/USD' => ['spot' => true, 'precision' => ['price' => 0.01]]]);
    app()->instance(ExchangeRepository::class, $repository);
    $response = $this->actingAs(User::factory()->create())->getJson(route('markets.options', 'kraken'))
        ->assertStatus($status)->assertJsonPath('code', $code)->assertJsonMissingPath('symbols')
        ->assertDontSee('SECRET-KEY')->assertDontSee('signed-url');
    expect(Cache::has(MarketCatalog::PAIRS_CACHE_PREFIX.$exchange->exchange_id))->toBeFalse();
    $reference = $response->json('reference');
    Log::shouldHaveReceived('warning')->once()->with('Market catalogue request failed.', Mockery::on(fn (array $context): bool => $context['reference'] === $reference && $context['reason'] === $code
        && $context['exception_class'] === $class && ! str_contains(json_encode($context), 'SECRET-KEY')));
    $this->getJson(route('markets.options', 'kraken'))->assertOk()->assertJsonPath('symbols.0.value', 'BTC/USD');
})->with([
    [AuthenticationError::class, 'authentication_required', 422],
    [PermissionDenied::class, 'access_denied', 503],
    [RateLimitExceeded::class, 'rate_limited', 503],
    [DDoSProtection::class, 'rate_limited', 503],
    [RequestTimeout::class, 'timeout', 504],
    [ExchangeNotAvailable::class, 'connection_failed', 503],
    [NotSupported::class, 'unsupported', 422],
    [RuntimeException::class, 'catalogue_failed', 503],
]);

it('returns an empty successful catalogue only when the exchange returns no supported spot pairs', function () {
    Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->once();
    $repository->shouldReceive('describe')->once()->andReturn(['timeframes' => ['1m' => '1m']]);
    $repository->shouldReceive('spotMarkets')->once()->andReturn([]);
    app()->instance(ExchangeRepository::class, $repository);
    $this->actingAs(User::factory()->create())->getJson(route('markets.options', 'kraken'))
        ->assertOk()->assertJsonPath('symbols', [])->assertJsonMissingPath('code');
});

it('shows a maintenance error for stale metadata and preserves the user subscription page', function () {
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('all')->once()->andThrow(new MarketCatalogException('metadata_stale', 'Exchange metadata needs rebuilding.'));
    app()->instance(ExchangeMetadata::class, $metadata);
    $this->actingAs(User::factory()->create())->get(route('markets.index'))->assertOk()
        ->assertSee('Exchange metadata needs rebuilding.')->assertSee('Your markets')
        ->assertDontSee('No exchanges are available yet.');
});

it('invalidates compact pairs after an exchange configuration change and rejects unlisted spot subscriptions', function () {
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    Cache::put(MarketCatalog::PAIRS_CACHE_PREFIX.$exchange->exchange_id, ['symbols' => [], 'periods' => []], 300);
    Cache::put(MarketCatalog::EXCHANGES_CACHE, [], 3600);
    $exchange->update(['config' => '{"timeout":15000}']);
    expect(Cache::has(MarketCatalog::PAIRS_CACHE_PREFIX.$exchange->exchange_id))->toBeFalse()
        ->and(Cache::has(MarketCatalog::EXCHANGES_CACHE))->toBeFalse();
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->once();
    $repository->shouldReceive('describe')->once()->andReturn(['timeframes' => ['1m' => '1m']]);
    $repository->shouldReceive('spotMarkets')->once()->andReturn([]);
    app()->instance(ExchangeRepository::class, $repository);
    $this->actingAs(User::factory()->create())->post(route('markets.store'), ['exchange' => 'kraken', 'symbol' => 'FAKE/USD'])
        ->assertSessionHasErrors('symbol');
    expect(MarketSubscription::query()->count())->toBe(0);
});

it('shows the provider error on subscription submission too', function () {
    Exchange::query()->create(['class' => 'alpaca', 'name' => 'Alpaca', 'config' => '{}']);
    $this->actingAs(User::factory()->create())->post(route('markets.store'), ['exchange' => 'alpaca', 'symbol' => 'BTC/USD'])
        ->assertSessionHasErrors('exchange');
    expect(session('errors')->first('exchange'))->toContain('API credentials');
    expect(MarketSubscription::query()->count())->toBe(0);
});
