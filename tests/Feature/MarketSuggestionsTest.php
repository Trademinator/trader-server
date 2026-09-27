<?php

use App\Domain\MarketSuggestions\CandleEvidence;
use App\Domain\MarketSuggestions\Questionnaire;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketPreferenceProfile;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use Illuminate\Support\Facades\DB;

function suggestionAnswers(array $changes = []): array
{
    return array_replace(Questionnaire::defaults(), [
        'country' => 'CA', 'region' => 'ON', 'exchange' => 'kraken', 'reference_currency' => 'CAD',
        'access_confirmed' => true, 'holdings' => [['asset' => 'CAD', 'band' => '100_500'], ['asset' => 'BTC', 'band' => '100_500']],
        'allocation' => '100_500', 'goal' => 'grow', 'risk' => 'low', 'loss_impact' => 'no',
        'money_needed' => 'later', 'horizon' => 'days', 'experience' => 'some', 'monitoring' => 'daily',
    ], $changes);
}

function suggestionExchange(?array $markets = null): Exchange
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $repo = Mockery::mock(ExchangeRepository::class);
    $repo->shouldReceive('setExchange')->andReturnNull();
    $repo->shouldReceive('describe')->andReturn(['timeframes' => ['1m' => '1m', '1d' => '1d'], 'precisionMode' => \ccxt\TICK_SIZE]);
    $repo->shouldReceive('spotMarkets')->andReturn($markets ?? [
        'BTC/CAD' => ['spot' => true, 'active' => true, 'precision' => ['price' => 0.01], 'limits' => ['cost' => ['min' => 10]], 'taker' => 0.002],
        'ETH/CAD' => ['spot' => true, 'active' => true, 'precision' => ['price' => 0.01]],
        'SOL/USDT' => ['spot' => true, 'active' => true, 'precision' => ['price' => 0.01]],
        'SOL/CAD' => ['spot' => true, 'active' => false, 'precision' => ['price' => 0.01]],
    ]);
    app()->instance(ExchangeRepository::class, $repo);

    return $exchange;
}

function saveSuggestionAnswers(User $user, array $changes = []): void
{
    MarketPreferenceProfile::query()->updateOrCreate(['user_id' => $user->user_id], ['answers' => suggestionAnswers($changes)]);
}

function suggestionHistory(string $symbol = 'BTC/CAD', ?Closure $price = null, int $age = 0): void
{
    for ($i = 0; $i < 60; $i++) {
        $close = $price ? $price($i) : 100 + $i % 2;
        $timestamp = now()->startOfDay()->subDays(60 - $i + $age)->getTimestamp() * 1000;
        Ticker::query()->create(['exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1d', 'microtimestamp' => $timestamp,
            'payload' => json_encode(['microtimestamp' => $timestamp, 'open' => $close, 'high' => $close * 1.01, 'low' => $close * .99, 'close' => $close, 'volume' => 100])]);
    }
}

function currentRegionalReview(array $changes = []): void
{
    config(['market_suggestions.regional_reviews' => ['kraken' => ['CA-ON' => array_replace([
        'allowed' => true, 'reviewed_at' => now()->toDateString(), 'source' => 'https://example.test/fixture-access-review',
    ], $changes)]]]);
}

it('requires authentication for the questionnaire and all preference mutations', function () {
    $this->get(route('markets.suggestions'))->assertRedirect(route('login'));
    $this->put(route('markets.preferences.store'), [])->assertRedirect(route('login'));
    $this->delete(route('markets.preferences.destroy'))->assertRedirect(route('login'));
});

it('saves normalized encrypted answers for the current user and never subscribes automatically', function () {
    suggestionExchange();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $input = suggestionAnswers(['user_id' => $other->user_id, 'country' => 'ca', 'region' => 'on', 'holdings' => [['asset' => ' btc ', 'band' => '100_500'], ['asset' => '', 'band' => 'unsure']]]);
    $this->actingAs($user)->put(route('markets.preferences.store'), $input)->assertRedirect(route('markets.suggestions', ['show' => 1]));
    $profile = MarketPreferenceProfile::query()->findOrFail($user->user_id);
    expect($profile->answers['holdings'])->toBe([['asset' => 'BTC', 'band' => '100_500']]);
    expect($profile->answers['region'])->toBe('ON');
    expect($profile->toArray())->not->toHaveKey('answers');
    expect(DB::table('market_preference_profiles')->value('answers'))->not->toContain('holdings', 'BTC', 'country');
    expect(MarketPreferenceProfile::query()->where('user_id', $other->user_id)->exists())->toBeFalse();
    expect(MarketSubscription::query()->count())->toBe(0)->and(MarketFeed::query()->count())->toBe(0);
});

it('renders answers privately and cannot expose another user profile', function () {
    suggestionExchange();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    saveSuggestionAnswers($owner, ['target_asset' => 'PRIVATEASSET']);
    $this->actingAs($owner)->get(route('markets.suggestions'))->assertOk()->assertSee('PRIVATEASSET')->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($other)->get(route('markets.suggestions', ['user_id' => $owner->user_id]))->assertOk()->assertDontSee('PRIVATEASSET');
});

it('deletes only the signed in users answers and preserves users and subscriptions', function () {
    $exchange = suggestionExchange();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    saveSuggestionAnswers($owner);
    saveSuggestionAnswers($other);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/CAD', 'tick_size' => '.01']);
    MarketSubscription::query()->create(['market_id' => $market->market_id, 'user_id' => $owner->user_id, 'active' => true]);
    $this->actingAs($owner)->delete(route('markets.preferences.destroy'), ['user_id' => $other->user_id])->assertRedirect(route('markets.suggestions'));
    expect(MarketPreferenceProfile::query()->where('user_id', $owner->user_id)->exists())->toBeFalse();
    expect(MarketPreferenceProfile::query()->where('user_id', $other->user_id)->exists())->toBeTrue();
    expect(User::query()->count())->toBe(2)->and(MarketSubscription::query()->where('active', true)->count())->toBe(1);
});

it('validates residence holdings ranges and bounded input', function (array $changes, string $field) {
    suggestionExchange();
    $this->actingAs(User::factory()->create())->put(route('markets.preferences.store'), suggestionAnswers($changes))->assertSessionHasErrors($field);
    expect(MarketPreferenceProfile::query()->count())->toBe(0);
})->with([
    [['country' => 'XX'], 'country'], [['region' => ''], 'region'], [['region' => 'Atlantis'], 'region'],
    [['allocation' => '100000000000'], 'allocation'], [['goal' => 'accumulate', 'target_asset' => ''], 'target_asset'],
    [['holdings' => [['asset' => 'btc', 'band' => 'unsure'], ['asset' => 'BTC', 'band' => 'unsure']]], 'holdings.0.asset'],
    [['holdings' => array_fill(0, 6, ['asset' => 'BTC', 'band' => 'unsure'])], 'holdings'],
    [['holdings' => [['asset' => '<script>', 'band' => 'unsure']]], 'holdings.0.asset'],
]);

it('shows funding matches as exploratory when history or regional evidence is missing', function () {
    suggestionExchange();
    $user = User::factory()->create();
    saveSuggestionAnswers($user);
    $response = $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertOk()
        ->assertSee('Review BTC/CAD')->assertSee('Explore only')->assertSee('Insufficient stored history')
        ->assertSee('You hold both BTC and CAD')->assertDontSee('Review SOL/USDT')->assertDontSee('Review SOL/CAD');
    expect($response->viewData('results')['items'][0]['symbol'])->toBe('BTC/CAD');
    expect(Market::query()->count())->toBe(0)->and(MarketSubscription::query()->count())->toBe(0);
});

it('opens a prefilled review and only subscribes after explicit confirmation', function () {
    suggestionExchange();
    $user = User::factory()->create();
    saveSuggestionAnswers($user);
    $this->actingAs($user)->get(route('markets.index', ['exchange' => 'kraken', 'symbol' => 'BTC/CAD']))->assertOk()->assertSee('data-old-symbol="BTC/CAD"', false)->assertSee('Help me choose pairs');
    expect(MarketSubscription::query()->count())->toBe(0);
    $this->post(route('markets.store'), ['exchange' => 'kraken', 'symbol' => 'BTC/CAD'])->assertRedirect(route('markets.index'));
    $this->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('Already subscribed')->assertDontSee('Review BTC/CAD');
    expect(MarketSubscription::query()->count())->toBe(1);
});

it('applies exclusions conversion preferences and minimum order budgets', function () {
    suggestionExchange([
        'BTC/CAD' => ['spot' => true, 'active' => true, 'precision' => ['price' => .01], 'limits' => ['cost' => ['min' => 1000]]],
        'ETH/CAD' => ['spot' => true, 'active' => true, 'precision' => ['price' => .01]],
        'ETH/USDT' => ['spot' => true, 'active' => true, 'precision' => ['price' => .01]],
    ]);
    $user = User::factory()->create();
    saveSuggestionAnswers($user, ['excluded_assets' => 'ETH']);
    $response = $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('No suitable matches')->assertSee('Minimum order exceeds');
    expect($response->viewData('results')['items'])->toBeEmpty();
});

it('permits a single conversion as exploratory without inventing its costs', function () {
    suggestionExchange([
        'BTC/CAD' => ['spot' => true, 'active' => true, 'precision' => ['price' => .01]],
        'ETH/BTC' => ['spot' => true, 'active' => true, 'precision' => ['price' => .0001]],
    ]);
    $user = User::factory()->create();
    saveSuggestionAnswers($user, ['holdings' => [['asset' => 'CAD', 'band' => '100_500']], 'conversions' => 'one']);
    $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('Review ETH/BTC')->assertSee('Conversion fees, minimums and available amounts have not been checked');
});

it('refuses conflicting financial or monitoring preferences without a live catalogue fetch', function (array $changes, string $message) {
    Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $repo = Mockery::mock(ExchangeRepository::class);
    $repo->shouldNotReceive('setExchange');
    app()->instance(ExchangeRepository::class, $repo);
    $user = User::factory()->create();
    saveSuggestionAnswers($user, $changes);
    $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee($message)->assertSee('No suitable matches');
})->with([
    [['loss_impact' => 'yes'], 'essential expenses'],
    [['money_needed' => 'soon'], 'need this money soon'],
    [['horizon' => 'hours', 'monitoring' => 'daily'], 'conflicts with your available monitoring time'],
]);

it('honors regional denials and per-pair restrictions over self reported access', function () {
    suggestionExchange();
    $user = User::factory()->create();
    saveSuggestionAnswers($user);
    currentRegionalReview(['allowed' => false]);
    $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('regional review excludes')->assertDontSee('Review BTC/CAD');
    currentRegionalReview(['allowed_symbols' => ['ETH/CAD']]);
    $this->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('Review ETH/CAD')->assertDontSee('Review BTC/CAD');
});

it('keeps stale regional approvals exploratory even with adequate price history', function () {
    suggestionExchange();
    suggestionHistory();
    $user = User::factory()->create();
    saveSuggestionAnswers($user);
    currentRegionalReview(['reviewed_at' => now()->subDays(91)->toDateString()]);
    $response = $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertOk();
    expect($response->viewData('results')['items'][0]['explore'])->toBeTrue();
    expect($response->viewData('results')['items'][0]['evidence']['known'])->toBeTrue();
});

it('uses continuous completed history and excludes excessive observed swings', function () {
    suggestionExchange();
    suggestionHistory();
    suggestionHistory('ETH/CAD', fn ($i) => $i < 45 ? 100 : 50);
    currentRegionalReview();
    $user = User::factory()->create();
    saveSuggestionAnswers($user);
    $response = $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('Matches preference screens')->assertSee('Historical evidence')->assertDontSee('Review ETH/CAD');
    expect($response->viewData('results')['items'][0]['evidence']['candles'])->toBe(60);
    expect($response->viewData('results')['items'][0]['explore'])->toBeFalse();
});

it('does not use stale gapped malformed or insufficient history as known risk', function (string $kind) {
    suggestionHistory(age: $kind === 'stale' ? 10 : 0);
    if ($kind === 'gap') {
        Ticker::query()->orderBy('microtimestamp')->skip(20)->first()->delete();
    }
    if ($kind === 'invalid') {
        Ticker::query()->first()->update(['payload' => '{"close":0}']);
    }
    if ($kind === 'short') {
        Ticker::query()->orderBy('microtimestamp')->limit(50)->get()->each->delete();
    }
    expect(app(CandleEvidence::class)->inspect('kraken', 'BTC/CAD', '1d', 'days')['known'])->toBeFalse();
})->with(['stale', 'gap', 'invalid', 'short']);

it('ignores unfinished candles and measures inverse prices when accumulating the base asset', function () {
    suggestionHistory(price: fn ($i) => 100 + $i);
    Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/CAD', 'period' => '1d', 'microtimestamp' => now()->startOfDay()->getTimestamp() * 1000,
        'payload' => json_encode(['open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 100])]);
    $normal = app(CandleEvidence::class)->inspect('kraken', 'BTC/CAD', '1d', 'days');
    $inverse = app(CandleEvidence::class)->inspect('kraken', 'BTC/CAD', '1d', 'days', true);
    expect($normal['candles'])->toBe(60)->and($normal['drawdown'])->toBe(0.0);
    expect($inverse['drawdown'])->toBeGreaterThan(.3);
});

it('keeps collection and profile deletion independent of account deletion', function () {
    $user = User::factory()->create();
    saveSuggestionAnswers($user);
    $user->delete();
    expect(MarketPreferenceProfile::query()->count())->toBe(0);
});

it('checks the value of held base units against minimum order size without assuming a quote balance', function () {
    suggestionExchange(['BTC/CAD' => ['spot' => true, 'active' => true, 'precision' => ['price' => .01], 'limits' => ['cost' => ['min' => 200]]]]);
    $user = User::factory()->create();
    saveSuggestionAnswers($user, ['holdings' => [['asset' => 'BTC', 'band' => 'under_100']], 'allocation' => '100_500']);
    $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('No suitable matches')->assertSee('Minimum order exceeds');
});

it('never treats dollars as stablecoins when evaluating minimum order affordability', function () {
    suggestionExchange(['BTC/USDT' => ['spot' => true, 'active' => true, 'precision' => ['price' => .01], 'limits' => ['cost' => ['min' => 200]]]]);
    $user = User::factory()->create();
    saveSuggestionAnswers($user, ['holdings' => [['asset' => 'USDT', 'band' => 'under_100']], 'allocation' => 'under_100', 'reference_currency' => 'USD']);
    $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('Review BTC/USDT')->assertSee('affordability in USDT has not been verified');
});

it('keeps a country denial effective when a province approval exists', function () {
    suggestionExchange();
    currentRegionalReview();
    config(['market_suggestions.regional_reviews.kraken.CA' => ['allowed' => false]]);
    $user = User::factory()->create();
    saveSuggestionAnswers($user);
    $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('regional review excludes')->assertDontSee('Review BTC/CAD');
});

it('filters known stablecoins and restricts an accumulation goal to the chosen asset', function () {
    suggestionExchange([
        'BTC/USDT' => ['spot' => true, 'active' => true, 'precision' => ['price' => .01]],
        'BTC/CAD' => ['spot' => true, 'active' => true, 'precision' => ['price' => .01]],
        'ETH/CAD' => ['spot' => true, 'active' => true, 'precision' => ['price' => .01]],
    ]);
    $user = User::factory()->create();
    saveSuggestionAnswers($user, ['goal' => 'accumulate', 'target_asset' => 'BTC', 'exclude_stablecoins' => true]);
    $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('Review BTC/CAD')->assertDontSee('Review BTC/USDT')->assertDontSee('Review ETH/CAD');
});

it('does not use a stale short history to convert minimum amount into order cost', function () {
    suggestionHistory(age: 10);
    Ticker::query()->orderBy('microtimestamp')->limit(50)->get()->each->delete();
    $evidence = app(CandleEvidence::class)->inspect('kraken', 'BTC/CAD', '1d', 'days');
    expect($evidence['known'])->toBeFalse()->and($evidence['last_close'])->toBeNull();
});
