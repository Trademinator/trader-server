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

it('opens a detailed review and subscribes directly only after explicit confirmation', function () {
    suggestionExchange();
    $user = User::factory()->create();
    saveSuggestionAnswers($user);
    $reviewUrl = route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => 'BTC/CAD']);
    $this->actingAs($user)->get(route('markets.suggestions', ['show' => 1]))->assertSee(e($reviewUrl), false);
    $this->get($reviewUrl)->assertOk()->assertSee('Exactly how your score is calculated')
        ->assertSee('Subscribe to BTC/CAD')->assertSee('action="'.route('markets.store').'"', false)
        ->assertSee('name="exchange" value="kraken"', false)->assertSee('name="symbol" value="BTC/CAD"', false)
        ->assertSee('name="_token"', false)->assertHeader('Cache-Control', 'no-store, private');
    expect(MarketSubscription::query()->count())->toBe(0);
    $this->post(route('markets.store'), ['exchange' => 'kraken', 'symbol' => 'BTC/CAD'])->assertRedirect(route('markets.index'));
    $this->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('Already subscribed')->assertSee('Review BTC/CAD');
    $this->get($reviewUrl)->assertOk()->assertSee('Already subscribed')->assertDontSee('Subscribe to BTC/CAD');
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

it('requires authentication and uses only the signed in users review preferences', function () {
    suggestionExchange();
    $owner = User::factory()->create();
    saveSuggestionAnswers($owner);
    $url = route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => 'BTC/CAD', 'user_id' => $owner->user_id]);

    $this->get($url)->assertRedirect(route('login'));
    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->get($url)->assertRedirect(route('markets.suggestions'));
    $this->getJson($url)->assertStatus(409)->assertJsonPath('message', 'Your saved preferences are no longer available. Return to pair suggestions.');
    $this->assertDatabaseCount('market_subscriptions', 0);
});

it('explains the exact points without making them a profit or confidence estimate', function (array $changes, int $score, array $points) {
    suggestionExchange();
    $user = User::factory()->create();
    saveSuggestionAnswers($user, $changes);

    $response = $this->actingAs($user)->get(route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => 'BTC/CAD']))
        ->assertOk()->assertSee('KNN confidence score')->assertSee('Unknown')->assertSee('Explore only');

    expect($response->viewData('item')['score'])->toBe($score);
    expect(array_column($response->viewData('item')['score_breakdown'], 'points'))->toBe($points);
    expect($response->viewData('item')['evidence']['series'])->toBeEmpty();
    $this->assertDatabaseCount('market_feeds', 0);
})->with([
    'both held' => [[], 100, [40, 20, 10, 30]],
    'quote held' => [['holdings' => [['asset' => 'CAD', 'band' => '100_500']]], 90, [40, 20, 0, 30]],
    'base held' => [['holdings' => [['asset' => 'BTC', 'band' => '100_500']]], 80, [40, 0, 10, 30]],
    'holdings unknown' => [['holdings' => []], 30, [0, 0, 0, 30]],
    'accumulate base' => [['goal' => 'accumulate', 'target_asset' => 'BTC'], 90, [40, 20, 10, 20]],
]);

it('returns only the reviewed pairs valid closed candles and rechecks fresh history on refresh', function () {
    $this->freezeTime();
    suggestionExchange();
    suggestionHistory();
    suggestionHistory('ETH/CAD');
    $user = User::factory()->create();
    saveSuggestionAnswers($user);
    currentRegionalReview();
    $timestamp = now()->startOfDay()->getTimestamp() * 1000;
    Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/CAD', 'period' => '1d', 'microtimestamp' => $timestamp,
        'payload' => json_encode(['open' => 102, 'high' => 103, 'low' => 101, 'close' => 102, 'volume' => 123])]);
    $url = route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => 'BTC/CAD']);

    $response = $this->actingAs($user)->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('symbol', 'BTC/CAD')->assertJsonPath('evidence.known', true)
        ->assertJsonPath('review_type', 'preference')
        ->assertJsonCount(60, 'evidence.series')->assertJsonMissingPath('answers');

    $series = $response->json('evidence.series');
    expect($series[0]['time'])->toBe(now()->startOfDay()->subDays(60)->getTimestamp());
    expect($series[59]['time'])->toBe(now()->startOfDay()->subDay()->getTimestamp());
    expect($series[59]['close'])->toBe(101);
    $this->travel(1)->days();
    $this->getJson($url)->assertOk()->assertJsonCount(61, 'evidence.series')->assertJsonPath('evidence.series.60.close', 102);
    $this->assertDatabaseCount('market_subscriptions', 0);
    $this->assertDatabaseCount('market_feeds', 0);
});

it('shows risk units formulas and the effective holding window from the same evidence', function () {
    $this->freezeTime();
    suggestionExchange();
    suggestionHistory();
    currentRegionalReview();
    $user = User::factory()->create();
    saveSuggestionAnswers($user, ['goal' => 'accumulate', 'target_asset' => 'BTC']);

    $response = $this->actingAs($user)->get(route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => 'BTC/CAD']))
        ->assertOk()->assertSee('Inverse price measurement')->assertSee('BTC per CAD')->assertSee('CAD per BTC')
        ->assertSee('3 × 1d = 72 hours')->assertSee('1.00%');

    $evidence = $response->viewData('item')['evidence'];
    expect($evidence['inverse'])->toBeTrue();
    expect($evidence['series'][0]['close'])->toBe(100.0);
    expect($evidence['drawdown'])->toEqualWithDelta(1 / 101, 0.000000001);
});

it('does not present stale gapped or corrupt chart history as usable risk evidence', function (string $kind, int $count, string $message) {
    $this->freezeTime();
    suggestionExchange();
    suggestionHistory(age: $kind === 'stale' ? 10 : 0);
    if ($kind === 'gap') {
        Ticker::query()->orderBy('microtimestamp')->skip(20)->first()->delete();
    }
    if ($kind === 'corrupt') {
        Ticker::query()->orderByDesc('microtimestamp')->first()->update(['payload' => '{"close":0}']);
    }
    $user = User::factory()->create();
    saveSuggestionAnswers($user);

    $this->actingAs($user)->getJson(route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => 'BTC/CAD']))
        ->assertOk()->assertJsonPath('evidence.known', false)->assertJsonPath('evidence.message', $message)
        ->assertJsonCount($count, 'evidence.series');
})->with([
    ['stale', 60, 'Stored price history is stale.'],
    ['gap', 59, 'Stored price history has gaps.'],
    ['corrupt', 0, 'Stored candles contain invalid data.'],
]);

it('refuses obsolete or invented shortlist links and never offers subscription on those reviews', function (array $changes, string $symbol) {
    suggestionExchange();
    $user = User::factory()->create();
    saveSuggestionAnswers($user, $changes);
    $url = route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => $symbol]);

    $this->actingAs($user)->get($url)->assertStatus(409)->assertSee('no longer in your current shortlist')->assertDontSee('data-review-subscribe', false);
    $this->getJson($url)->assertStatus(409)->assertJsonMissingPath('evidence');
    $this->assertDatabaseCount('market_subscriptions', 0);
})->with([
    'pair removed by new answer' => [['excluded_assets' => 'BTC'], 'BTC/CAD'],
    'essential expenses change' => [['loss_impact' => 'yes'], 'BTC/CAD'],
    'unknown pair' => [[], 'FAKE/CAD'],
]);

it('rejects a different exchange and invalid review identifiers', function () {
    suggestionExchange();
    $user = User::factory()->create();
    saveSuggestionAnswers($user);

    $this->actingAs($user)->getJson(route('markets.suggestions.review', ['exchange' => 'bitso', 'symbol' => 'BTC/CAD']))->assertNotFound();
    $this->getJson(route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => ['BTC/CAD']]))
        ->assertUnprocessable()->assertJsonValidationErrors('symbol');
    $this->getJson(route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => '<script>alert(1)</script>']))
        ->assertUnprocessable()->assertJsonValidationErrors('symbol');
});

it('shows a readable review failure when catalogue loading fails', function () {
    Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->andThrow(new RuntimeException('fixture outage'));
    app()->instance(ExchangeRepository::class, $repository);
    $user = User::factory()->create();
    saveSuggestionAnswers($user);
    $url = route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => 'BTC/CAD']);

    $this->actingAs($user)->get($url)->assertStatus(503)->assertSee('Pair review unavailable')->assertSee('Reference:')->assertDontSee('fixture outage')->assertDontSee('data-review-subscribe', false);
    $this->getJson($url)->assertStatus(503)->assertJsonMissingPath('evidence');
    $this->assertDatabaseCount('market_subscriptions', 0);
});

it('escapes exchange names and never renders saved answers as executable markup', function () {
    $exchange = suggestionExchange();
    $exchange->update(['name' => '<script>alert(42)</script>']);
    $user = User::factory()->create();
    saveSuggestionAnswers($user);

    $this->actingAs($user)->get(route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => 'BTC/CAD']))
        ->assertOk()->assertSee('&lt;script&gt;alert(42)&lt;/script&gt;', false)->assertDontSee('<script>alert(42)</script>', false);
});

it('opens a technical review for an owned subscription when a preference match is unavailable', function (?array $answers, bool $active, string $notice) {
    $this->freezeTime();
    $exchange = suggestionExchange();
    suggestionHistory();
    $user = User::factory()->create();
    if ($answers !== null) {
        saveSuggestionAnswers($user, $answers);
    }
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/CAD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1d']);
    $subscription = MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => $active]);
    $url = route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => 'BTC/CAD']);

    $this->actingAs($user)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Technical review')->assertSee($notice)->assertSee('Closed-candle price history')
        ->assertDontSee('fits your preferences')->assertDontSee('data-review-subscribe', false);
    $this->getJson($url)->assertOk()->assertJsonPath('symbol', 'BTC/CAD')->assertJsonPath('exchange', 'kraken')
        ->assertJsonPath('review_type', 'technical')->assertJsonCount(60, 'evidence.series')->assertJsonMissingPath('answers');
    expect($subscription->fresh()->active)->toBe($active);
    expect(MarketPreferenceProfile::query()->find($user->user_id)?->answers)->toBe($answers === null ? null : suggestionAnswers($answers));
})->with([
    'no preferences' => [null, true, 'No saved preferences'],
    'different exchange' => [['exchange' => 'bitso'], true, 'different exchange'],
    'removed from shortlist' => [['excluded_assets' => 'BTC'], true, 'not in your current suggestions'],
    'inactive subscription' => [null, false, 'No saved preferences'],
]);

it('does not use another users subscription to grant a technical review', function () {
    $exchange = suggestionExchange();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    saveSuggestionAnswers($other, ['exchange' => 'bitso']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/CAD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => $owner->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $url = route('markets.suggestions.review', ['exchange' => 'kraken', 'symbol' => 'BTC/CAD', 'user_id' => $owner->user_id]);

    $this->actingAs($other)->getJson($url)->assertNotFound()->assertJsonMissingPath('evidence');
});
