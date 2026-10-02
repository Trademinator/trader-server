<?php

namespace App\Providers;

use App\Domain\MarketData\AllowMarketSubscriptions;
use App\Domain\MarketData\MarketSubscriptionEntitlement;
use App\Domain\Operations\ProductionSecurityConfiguration;
use App\Events\MarketSubscriptionCreated;
use App\Listeners\EnsureCoinGeckoMarketMapping;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        // Keep Artisan recovery commands available even when production HTTP settings are unsafe.
        if (! $this->app->runningInConsole()) {
            (new ProductionSecurityConfiguration)->assertSafe($this->app['config']);
        }

        RateLimiter::for('registration', function (Request $request): array {
            return [
                Limit::perMinute(5)->by('registration-minute:'.$request->ip()),
                Limit::perHour(20)->by('registration-hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('market-subscriptions', function (Request $request): array {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return [
                Limit::perMinute(6)->by('market-subscriptions-user:'.$userId),
                Limit::perMinute(30)->by('market-subscriptions-ip-minute:'.$request->ip()),
                Limit::perHour(120)->by('market-subscriptions-ip-hour:'.$request->ip()),
            ];
        });

        Event::listen(MarketSubscriptionCreated::class, EnsureCoinGeckoMarketMapping::class);
    }
}
