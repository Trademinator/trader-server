<?php

use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

it('keeps market management available when a compiled deployment route table lacks suggestion routes', function (string $missing) {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/CAD', 'tick_size' => '.01']);
    $subscription = MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $names = ['markets.suggestions', 'markets.preferences.store', 'markets.preferences.destroy'];
    $routes = new RouteCollection;
    foreach (app('router')->getRoutes() as $route) {
        if (! in_array($route->getName(), $missing === 'all' ? $names : [$missing], true)) {
            $routes->add($route);
        }
    }
    app('router')->setCompiledRoutes($routes->compile());
    app('url')->setRoutes(app('router')->getRoutes());
    expect(Route::has($names))->toBeFalse();

    $this->actingAs($user)->get('/markets')->assertOk()
        ->assertSee('Pair suggestions are temporarily unavailable')
        ->assertSee('Add a market')->assertSee('Your markets')->assertSee('BTC/CAD')
        ->assertDontSee('href="'.url('/markets/suggestions').'"', false);
    expect($subscription->fresh()->active)->toBeTrue();
    $this->delete(route('markets.destroy', $subscription->market_subscription_id))->assertRedirect(route('markets.index'));
    expect($subscription->fresh()->active)->toBeFalse();
})->with(['markets.suggestions', 'markets.preferences.store', 'markets.preferences.destroy', 'all']);
