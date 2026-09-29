<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

it('restricts intelligence pages to the active subscription owner and escapes market text', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => '<script>alert(1)</script>', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'active']);
    $subscription = MarketSubscription::query()->create(['user_id' => $owner->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $url = route('markets.intelligence', $subscription->getKey());

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs($other)->get($url)->assertNotFound();
    $this->actingAs($owner)->get($url)->assertSee('HOLD')->assertSee('0.0%')
        ->assertSee('Training has not completed')->assertSee('What is missing?')
        ->assertSee('M2/M3 jobs alone do not train an M4 model.')
        ->assertSee('ETA unavailable')->assertSee('fallback zeros')
        ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    $subscription->update(['active' => false]);
    $this->get($url)->assertNotFound();
});

it('renders actual history counts and failed validation requirements for an abstaining model', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $path = sys_get_temp_dir().'/trademinator-page-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.patterns.enabled' => true]);
    $owner = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'active']);
    $subscription = MarketSubscription::query()->create(['user_id' => $owner->user_id, 'market_id' => $market->market_id, 'active' => true]);
    try {
        $manifest = IntelligenceFixtures::snapshot(227);
        app(IntelligenceTrainer::class)->train($manifest['dataset_id']);

        $response = $this->actingAs($owner)->get(route('markets.intelligence', $subscription->getKey()));

        $response->assertOk()->assertSee('227 out of 250 retained neighbors')
            ->assertSee('227 out of 380')->assertSee('K selection requirements')
            ->assertSee('At least 50')->assertSee('Missing / failed')
            ->assertSee('Not evaluated: K selection must pass first.')
            ->assertSee('Pattern training history')->assertSee('0 out of 100')
            ->assertSee('<progress', false)->assertSee('role="progressbar"', false);
    } finally {
        File::deleteDirectory($path);
    }
});
