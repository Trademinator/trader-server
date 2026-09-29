<?php

use App\Domain\MarketSuggestions\MarketDiscovery;
use App\Domain\MarketSuggestions\Questionnaire;
use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketPreferenceProfile;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function dashboardSuggestionSetup(User $user, array $changes = [], array $symbols = ['AAA/CAD', 'BTC/CAD', 'ETH/CAD']): Exchange
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->andReturnNull();
    $repository->shouldReceive('describe')->andReturn(['timeframes' => ['1m' => '1m', '1d' => '1d'], 'precisionMode' => \ccxt\TICK_SIZE]);
    $repository->shouldReceive('spotMarkets')->andReturn(array_fill_keys($symbols, ['spot' => true, 'active' => true, 'precision' => ['price' => 0.01]]));
    app()->instance(ExchangeRepository::class, $repository);
    MarketPreferenceProfile::query()->create(['user_id' => $user->getKey(), 'answers' => array_replace(Questionnaire::defaults(), [
        'country' => 'CA', 'region' => 'ON', 'exchange' => 'kraken', 'reference_currency' => 'CAD',
        'access_confirmed' => true, 'holdings' => [['asset' => 'CAD', 'band' => '100_500']],
        'allocation' => '100_500', 'goal' => 'grow', 'risk' => 'low', 'loss_impact' => 'no',
        'money_needed' => 'later', 'horizon' => 'days', 'experience' => 'some', 'monitoring' => 'daily',
    ], $changes)]);
    config(['dashboard.discovery_enabled' => true, 'features.coingecko.enabled' => true]);
    Cache::put(MarketDiscovery::CACHE_KEY, ['observed_at_ms' => now()->getTimestampMs(),
        'expires_at_ms' => now()->addHour()->getTimestampMs(), 'coins' => [
            'ETH' => ['coin_id' => 'ethereum', 'activity_ratio' => 10, 'change_24h' => 4],
            'BTC' => ['coin_id' => 'bitcoin', 'activity_ratio' => 2, 'change_24h' => 1],
        ]], 3600);
    Http::preventStrayRequests();

    return $exchange;
}

it('uses saved preferences before activity and keeps missing-history results exploratory without subscribing', function () {
    $user = User::factory()->create();
    dashboardSuggestionSetup($user, ['excluded_assets' => 'ETH']);
    $response = $this->actingAs($user)->get(route('dashboard.suggestions'))->assertOk()->assertSee('Explore only')
        ->assertSee('BTC/CAD')->assertDontSee('ETH/CAD')->assertHeader('Cache-Control', 'no-store, private');
    expect(array_column($response->viewData('results')['items'], 'symbol'))->toBe(['BTC/CAD', 'AAA/CAD']);
    $this->assertDatabaseCount('market_subscriptions', 0);
    $this->assertDatabaseCount('market_feeds', 0);
    $this->assertDatabaseCount('markets', 0);
    Http::assertNothingSent();
    $profile = MarketPreferenceProfile::query()->findOrFail($user->getKey());
    $profile->update(['answers' => array_replace($profile->answers, ['loss_impact' => 'yes'])]);
    $this->get(route('dashboard.suggestions'))->assertOk()->assertSee('No trading shortlist')->assertDontSee('Review market');
});

it('does not boost an ambiguous asset before the bounded history screen', function () {
    $user = User::factory()->create();
    $exchange = dashboardSuggestionSetup($user);
    config(['market_suggestions.candidate_limit' => 1]);
    $market = Market::query()->create(['exchange_id' => $exchange->getKey(), 'symbol' => 'ETH/CAD', 'tick_size' => '.01']);
    CoinGeckoMarketMapping::query()->create(['market_id' => $market->getKey(), 'base_symbol' => 'ETH', 'vs_currency' => 'cad', 'status' => 'ambiguous']);
    $response = $this->actingAs($user)->get(route('dashboard.suggestions'))->assertOk();
    expect(array_column($response->viewData('results')['items'], 'symbol'))->toBe(['BTC/CAD']);
    Cache::forget(MarketDiscovery::CACHE_KEY);
    $response = $this->get(route('dashboard.suggestions'))->assertOk()->assertSee('Current activity context is unavailable');
    expect(array_column($response->viewData('results')['items'], 'symbol'))->toBe(['AAA/CAD']);
});

it('scopes suggestions and dismissals to the verified user, with expiry and restoration', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    dashboardSuggestionSetup($owner);
    $url = route('dashboard.suggestions');
    $dismiss = route('dashboard.suggestions.dismiss');
    $this->get($url)->assertRedirect(route('login'));
    $this->post($dismiss, [])->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get($url)->assertRedirect(route('verification.notice'));
    $this->actingAs($other)->get($url.'?user_id='.$owner->getKey())->assertOk()->assertSee('Help me choose markets')->assertDontSee('BTC/CAD');
    $this->actingAs($owner)->post($dismiss, ['exchange' => 'kraken', 'symbol' => 'ETH/CAD', 'user_id' => $other->getKey()])->assertRedirect(route('dashboard'));
    $this->assertDatabaseHas('market_suggestion_dismissals', ['user_id' => $owner->getKey(), 'symbol' => 'ETH/CAD']);
    $this->assertDatabaseMissing('market_suggestion_dismissals', ['user_id' => $other->getKey()]);
    $this->get($url)->assertDontSee('ETH/CAD');
    DB::table('market_suggestion_dismissals')->update(['dismissed_at' => now()->subDays(31)]);
    $this->get($url)->assertSee('ETH/CAD');
    $this->post($dismiss, ['exchange' => 'kraken', 'symbol' => '<script>'])->assertSessionHasErrors('symbol');
    $this->actingAs($other)->delete(route('dashboard.suggestions.restore'))->assertRedirect(route('dashboard'));
    $this->assertDatabaseCount('market_suggestion_dismissals', 1);
    $this->actingAs($owner)->delete(route('dashboard.suggestions.restore'))->assertRedirect(route('dashboard'));
    $this->assertDatabaseCount('market_suggestion_dismissals', 0);
});

it('can review an additional dashboard match after excluding followed markets and rechecks dismissal on refresh', function () {
    $user = User::factory()->create();
    $exchange = dashboardSuggestionSetup($user, [], ['AAA/CAD', 'BBB/CAD', 'CCC/CAD', 'DDD/CAD', 'EEE/CAD', 'FFF/CAD']);
    foreach (['AAA', 'BBB', 'CCC', 'DDD', 'EEE'] as $base) {
        $market = Market::query()->create(['exchange_id' => $exchange->getKey(), 'symbol' => $base.'/CAD', 'tick_size' => '.01']);
        MarketSubscription::query()->create(['user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    }
    $review = route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => 'FFF/CAD', 'discovery' => 1]);
    $this->actingAs($user)->get(route('dashboard.suggestions'))->assertOk()->assertSee(e($review), false)->assertDontSee('AAA/CAD');
    $this->get($review)->assertOk()->assertSee('Subscribe to FFF/CAD');
    $this->getJson($review)->assertOk()->assertJsonPath('symbol', 'FFF/CAD');
    $this->post(route('dashboard.suggestions.dismiss'), ['exchange' => 'kraken', 'symbol' => 'FFF/CAD']);
    $this->getJson($review)->assertStatus(409);
    $this->assertDatabaseCount('market_subscriptions', 5);
});
