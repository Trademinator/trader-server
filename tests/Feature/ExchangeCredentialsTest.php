<?php

use App\Domain\MarketData\CandlePeriodSelector;
use App\Domain\MarketData\ExchangeCredentials;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketCatalogException;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Jobs\CollectMarketFeed;
use App\Models\Exchange;
use App\Models\ExchangeCredential;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use ccxt\AuthenticationError;
use ccxt\coinbase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

function collectionCredentialExchange(string $class = 'coinbase'): Exchange
{
    return Exchange::query()->create(['name' => ucfirst($class), 'class' => $class, 'config' => '{}']);
}

function collectionCredentialMarket(Exchange $exchange, User $user, string $symbol = 'BTC/USD'): Market
{
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);

    return $market;
}

function credentialCoinbaseRepository(): ExchangeRepository
{
    return new class extends ExchangeRepository
    {
        public array $requests = [];

        protected function newClient(string $class, array $settings): ccxt\Exchange
        {
            $client = Mockery::mock(coinbase::class.'[request]', [$settings]);
            $client->set_markets([[
                'id' => 'BTC-USD', 'symbol' => 'BTC/USD', 'base' => 'BTC', 'quote' => 'USD',
                'type' => 'spot', 'spot' => true, 'precision' => ['price' => 0.01, 'amount' => 0.00000001],
            ]]);
            $client->shouldReceive('request')->andReturnUsing(function ($path, $api, $method, $params) use ($client): array {
                $this->requests[] = ['path' => $path, 'api' => $api, 'method' => $method, 'params' => $params, 'key' => $client->apiKey];

                return ['candles' => [['start' => (string) $params['start'], 'open' => '100', 'high' => '102', 'low' => '99', 'close' => '101', 'volume' => '10']]];
            });

            return $client;
        }

        public function client(): ccxt\Exchange
        {
            return $this->ccxtExchange;
        }
    };
}

it('preserves the legacy owner and recognizes additional configured owners without granting ordinary users access', function () {
    $legacy = User::factory()->create();
    $additional = User::factory()->create();
    $ordinary = User::factory()->create();
    config(['operations.owner_uuid' => $legacy->user_id, 'operations.owner_uuids' => [strtoupper($additional->user_id), 'invalid']]);

    expect(Gate::forUser($legacy)->allows('manage-server'))->toBeTrue();
    expect(Gate::forUser($additional)->allows('manage-server'))->toBeTrue();
    expect(Gate::forUser($ordinary)->allows('manage-server'))->toBeFalse();
});

it('uses a users own keys before shared owner keys without mixing legacy credential fields', function () {
    $exchange = collectionCredentialExchange();
    $exchange->update(['config' => json_encode(['apiKey' => 'legacy', 'secret' => 'legacy-secret', 'password' => 'legacy-passphrase', 'timeout' => 12345])]);
    $shared = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id, 'is_shared' => true]);
    $own = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id]);
    config(['operations.owner_uuid' => $shared->user_id]);

    $settings = app(ExchangeCredentials::class)->settings($exchange, $own->user);

    expect($settings)->toMatchArray([...$own->credentials, 'timeout' => 12345])->not->toHaveKey('password');
    $this->assertDatabaseCount('exchange_credential_rotations', 0);
});

it('round robins shared owners across resolver instances and excludes deleted and unshared keys', function () {
    $exchange = collectionCredentialExchange();
    $owners = ExchangeCredential::factory()->count(3)->create(['exchange_id' => $exchange->exchange_id, 'is_shared' => true]);
    config(['operations.owner_uuids' => $owners->pluck('user_id')->all()]);
    $ids = $owners->sortBy('exchange_credential_id')->pluck('exchange_credential_id')->all();
    $picked = [];

    for ($i = 0; $i < 6; $i++) {
        $picked[] = (new ExchangeCredentials)->resolve($exchange)->getKey();
    }

    expect($picked)->toBe([...$ids, ...$ids]);
    $owners[0]->delete();
    $owners[1]->update(['is_shared' => false]);
    expect((new ExchangeCredentials)->resolve($exchange)->getKey())->toBe($owners[2]->getKey());
    expect(DB::table('exchange_credential_rotations')->first())->toHaveProperties(['scope', 'last_credential_id']);
});

it('does not share former owners, suspended owners or ordinary users even when their row is marked shared', function () {
    $exchange = collectionCredentialExchange();
    $former = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id, 'is_shared' => true]);
    $suspended = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id, 'is_shared' => true]);
    $suspended->user->forceFill(['suspended_at' => now()])->save();
    config(['operations.owner_uuid' => $former->user_id]);
    expect(app(ExchangeCredentials::class)->resolve($exchange)->getKey())->toBe($former->getKey());
    config(['operations.owner_uuid' => null, 'operations.owner_uuids' => [$suspended->user_id]]);

    expect(app(ExchangeCredentials::class)->resolve($exchange))->toBeNull();
    expect(app(ExchangeCredentials::class)->resolve($exchange, User::factory()->create()))->toBeNull();
});

it('uses only active subscribers personal keys for their market before the owner pool', function () {
    $exchange = collectionCredentialExchange();
    $owner = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id, 'is_shared' => true]);
    $subscriber = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id]);
    $unrelated = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id]);
    config(['operations.owner_uuid' => $owner->user_id]);
    $market = collectionCredentialMarket($exchange, $subscriber->user);
    collectionCredentialMarket($exchange, $unrelated->user, 'ETH/USD');
    $resolver = app(ExchangeCredentials::class);

    expect($resolver->resolve($exchange, symbol: 'BTC/USD')->getKey())->toBe($subscriber->getKey());
    expect($resolver->resolve($exchange, $unrelated->user)->getKey())->toBe($unrelated->getKey());
    $market->subscriptions()->update(['active' => false]);
    expect($resolver->resolve($exchange, symbol: 'BTC/USD')->getKey())->toBe($owner->getKey());
    $owner->update(['is_shared' => false]);
    expect($resolver->resolve($exchange, symbol: 'BTC/USD'))->toBeNull();
});

it('keeps subscribed shared owners in the same rotation pool as other sharing owners', function () {
    $exchange = collectionCredentialExchange();
    $owners = ExchangeCredential::factory()->count(2)->create(['exchange_id' => $exchange->exchange_id, 'is_shared' => true]);
    config(['operations.owner_uuids' => $owners->pluck('user_id')->all()]);
    collectionCredentialMarket($exchange, $owners[0]->user);
    $resolver = app(ExchangeCredentials::class);

    $first = $resolver->resolve($exchange, symbol: 'BTC/USD');
    $second = $resolver->resolve($exchange, symbol: 'BTC/USD');

    expect([$first->getKey(), $second->getKey()])->toBe($owners->sortBy('exchange_credential_id')->pluck('exchange_credential_id')->all());
});

it('allows required-authentication adapters with personal keys without consuming the rotation cursor during access checks', function () {
    $exchange = collectionCredentialExchange('luno');
    $credential = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id]);
    $metadata = app(ExchangeMetadata::class);

    expect($metadata->assertUsable($exchange, user: $credential->user)['access']['state'])->toBe('authentication_required');
    $this->assertDatabaseCount('exchange_credential_rotations', 0);
    expect(fn () => $metadata->assertUsable($exchange, user: User::factory()->create()))->toThrow(MarketCatalogException::class);
});

it('keeps public collection and legacy credentials available when no user credentials are saved', function () {
    $exchange = collectionCredentialExchange();
    $repository = credentialCoinbaseRepository();
    $repository->setExchange($exchange, symbol: 'BTC/USD');
    $repository->fetchHistoryPage('BTC/USD', '1m', 1_700_000_000_000, 1_700_000_060_000, 1);

    expect($repository->requests[0]['api'])->toBe(['v3', 'public']);
    $exchange->update(['config' => json_encode(['apiKey' => 'legacy', 'secret' => 'legacy-secret'])]);
    expect(app(ExchangeCredentials::class)->settings($exchange)['apiKey'])->toBe('legacy');
});

it('uses personal Coinbase credentials and the authenticated endpoint for actual candle fetching and history pages', function () {
    $exchange = collectionCredentialExchange();
    $credential = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id]);
    $repository = credentialCoinbaseRepository();
    $repository->setExchange($exchange, ['verbose' => true], user: $credential->user);
    $repository->prepareCandleMarket('BTC/USD');

    $repository->fetch('BTC/USD', '1m', 1_700_000_000, 1_700_000_000);
    $repository->fetchHistoryPage('BTC/USD', '1m', 1_700_000_000_000, 1_700_000_060_000, 1);

    expect($repository->client()->verbose)->toBeFalse();
    expect($repository->client()->has['fetchCurrencies'])->toBeFalse();
    expect($repository->client()->enableRateLimit)->toBeTrue();
    foreach ($repository->requests as $request) {
        expect($request)->toMatchArray(['path' => 'brokerage/products/{product_id}/candles', 'api' => ['v3', 'private'], 'method' => 'GET', 'key' => $credential->credentials['apiKey']]);
        expect($request['params'])->toHaveKeys(['start', 'end']);
    }
    expect($repository->requests)->toHaveCount(2);
    $this->assertDatabaseCount('tickers', 1);
});

it('uses subscriber keys in the scheduled collector with no signed-in web user', function () {
    $this->travelTo('2026-10-01 12:00:00 UTC');
    $exchange = collectionCredentialExchange();
    $credential = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id]);
    $market = collectionCredentialMarket($exchange, $credential->user);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'queued', 'lease_token' => 'test-lease']);
    $repository = credentialCoinbaseRepository();
    app()->instance(ExchangeRepository::class, $repository);

    (new CollectMarketFeed($market->market_id, 'test-lease'))->handle(app(CandlePeriodSelector::class), $repository,
        app(MarketDataSynchronizer::class), app(TickerRepository::class), app(ExchangeMetadata::class));

    expect($feed->fresh()->status)->toBe('ready');
    expect($repository->requests)->not->toBeEmpty();
    expect(array_unique(array_column($repository->requests, 'key')))->toBe([$credential->credentials['apiKey']]);
    expect(serialize(new CollectMarketFeed($market->market_id, 'test-lease')))->not->toContain($credential->credentials['apiKey'], $credential->credentials['secret']);
});

it('removes credentials on account deletion and returns to shared or public access', function () {
    $exchange = collectionCredentialExchange();
    $credential = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id, 'is_shared' => true]);
    config(['operations.owner_uuid' => $credential->user_id]);
    $credential->user->delete();

    $this->assertDatabaseCount('exchange_credentials', 0);
    expect(app(ExchangeCredentials::class)->resolve($exchange))->toBeNull();
});

it('sanitizes authenticated exchange errors before they reach persisted job failures', function () {
    $client = Mockery::mock(ccxt\Exchange::class)->makePartial();
    $client->shouldReceive('fetch_ohlcv')->once()->andThrow(new AuthenticationError('SECRET_API_KEY Authorization: Bearer SECRET_TOKEN https://invalid.test/?signature=SECRET_SIGNATURE'));
    $repository = new TickerRepository;
    $repository->setExchange($client);

    try {
        $repository->fetch('BTC/USD', '1m', 1_700_000_000_000, 1, []);
        $this->fail('Expected authentication failure.');
    } catch (AuthenticationError $error) {
        expect($error->getMessage())->toContain('Settings → Exchange keys')->not->toContain('SECRET_API_KEY', 'SECRET_TOKEN', 'SECRET_SIGNATURE');
        expect($error->getPrevious())->toBeNull();
    }
});

it('never selects another exchanges credentials for an unsaved exchange', function () {
    $exchange = collectionCredentialExchange();
    $owner = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id, 'is_shared' => true]);
    config(['operations.owner_uuid' => $owner->user_id]);
    $unsaved = new Exchange(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);

    expect(app(ExchangeCredentials::class)->resolve($unsaved))->toBeNull();
    expect(app(ExchangeCredentials::class)->settings($unsaved))->toBe([]);
});

it('uses shared owner keys in the legacy OHLCV command without an anonymous preflight or an extra rotation', function () {
    $exchange = collectionCredentialExchange();
    $owners = ExchangeCredential::factory()->count(2)->create(['exchange_id' => $exchange->exchange_id, 'is_shared' => true]);
    config(['operations.owner_uuids' => $owners->pluck('user_id')->all()]);
    $repository = credentialCoinbaseRepository();
    app()->instance(ExchangeRepository::class, $repository);

    foreach ([1, 2] as $run) {
        $this->artisan('trademinator:fetch-ohlcv', [
            'exchange' => 'coinbase', 'symbol' => 'BTC/USD', 'period' => '1m',
            'from' => '2023-11-14 22:13:20 UTC', 'to' => '2023-11-14 22:13:20 UTC', '--debug' => true,
        ])->assertSuccessful();
    }

    expect(array_column($repository->requests, 'key'))->toBe($owners->sortBy('exchange_credential_id')->map(fn (ExchangeCredential $key) => $key->credentials['apiKey'])->all());
    expect($repository->client()->verbose)->toBeFalse();
});
