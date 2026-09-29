<?php

namespace App\Domain\MarketData;

use App\Domain\Operations\ActionLog;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MarketSubscriptions
{
    public function __construct(private readonly MarketSubscriptionEntitlement $entitlement, private readonly ExchangeRepository $exchanges, private readonly MarketCatalog $catalog, private readonly ExchangeMetadata $metadata) {}

    public function subscribe(User $user, Exchange $exchange, string $symbol, string $tickSize, bool $spotOnly = false): MarketSubscription
    {
        if ($user->suspended_at !== null) {
            throw new InvalidArgumentException('The account is suspended.');
        }
        if ($symbol === '' || mb_strlen($symbol) > 32 || ! preg_match('/^\d{1,12}(?:\.\d{1,18})?$/D', $tickSize)
            || (float) $tickSize <= 0) {
            throw new InvalidArgumentException('Provide a valid exchange symbol and positive decimal tick size (up to 18 fractional digits).');
        }
        if (! $this->entitlement->canSubscribe($user, $exchange, $symbol)) {
            throw new InvalidArgumentException('The account cannot subscribe to this market.');
        }
        if ($spotOnly) {
            // Reuse the bounded, server-side catalogue used by the form. Do not
            // reload all currencies and derivatives on the subsequent POST.
            $options = $this->catalog->forExchange($exchange);
            if ($options['periods'] === [] || ! in_array($symbol, array_column($options['symbols'], 'value'), true)) {
                throw new InvalidArgumentException('This exchange does not list the requested spot symbol with a supported candle period.');
            }
        } else {
            $this->metadata->assertUsable($exchange, spotOnly: false);
            $this->exchanges->setExchange($exchange);
            if (! array_intersect(array_keys($this->exchanges->periods()), CandleTimeframe::SUPPORTED)) {
                throw new InvalidArgumentException('This exchange does not offer a supported OHLCV candle period.');
            }
            if (! array_key_exists($symbol, $this->exchanges->markets())) {
                throw new InvalidArgumentException('This exchange does not list the requested symbol.');
            }
        }

        $subscription = DB::transaction(function () use ($user, $exchange, $symbol, $tickSize): MarketSubscription {
            if (User::query()->lockForUpdate()->findOrFail($user->user_id)->suspended_at !== null) {
                throw new InvalidArgumentException('The account is suspended.');
            }
            $market = Market::query()->firstOrCreate(
                ['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol], ['tick_size' => $tickSize]);
            if (bccomp((string) $market->tick_size, $tickSize, 18) !== 0) {
                throw new InvalidArgumentException('This market already has a different tick size.');
            }
            MarketFeed::query()->firstOrCreate(['market_id' => $market->market_id],
                ['status' => 'pending', 'next_pull_at' => now()]);
            $subscription = MarketSubscription::query()->firstOrCreate(
                ['user_id' => $user->user_id, 'market_id' => $market->market_id], ['active' => true]);
            if (! $subscription->active) {
                $subscription->update(['active' => true]);
            }
            $market->feed()->whereNull('lease_token')->update(['next_pull_at' => now(), 'status' => 'pending']);

            return $subscription;
        });

        app(ActionLog::class)->write('subscription.activated', ['subject_id' => $user->user_id,
            'subscription_id' => $subscription->market_subscription_id, 'market_id' => $subscription->market_id, 'outcome' => 'completed']);

        return $subscription;
    }

    public function unsubscribe(User $user, Market $market): void
    {
        DB::transaction(function () use ($user, $market): void {
            MarketSubscription::query()->where('user_id', $user->user_id)
                ->where('market_id', $market->market_id)->update(['active' => false]);
            if (! $market->subscriptions()->where('active', true)->exists()) {
                $market->feed()->whereNull('lease_token')->update(['status' => 'idle', 'next_pull_at' => null]);
            }
        });
        app(ActionLog::class)->write('subscription.deactivated', ['subject_id' => $user->user_id,
            'market_id' => $market->market_id, 'outcome' => 'completed']);
    }
}
