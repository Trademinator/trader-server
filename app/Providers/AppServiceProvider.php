<?php

namespace App\Providers;

use App\Domain\MarketData\AllowMarketSubscriptions;
use App\Domain\MarketData\MarketSubscriptionEntitlement;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MarketSubscriptionEntitlement::class, AllowMarketSubscriptions::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
