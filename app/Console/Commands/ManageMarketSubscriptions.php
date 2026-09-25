<?php

namespace App\Console\Commands;

use App\Domain\MarketData\MarketSubscriptions;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ManageMarketSubscriptions extends Command
{
    protected $signature = 'trademinator:market-subscription {action : subscribe, unsubscribe, or list}
        {user : User email or UUID} {exchange? : CCXT exchange ID} {symbol? : Market symbol, e.g. BTC/USD}
        {--tick-size= : Minimum price increment from the exchange market metadata}';

    protected $description = 'Manage user subscriptions that drive shared market collection';

    public function handle(MarketSubscriptions $subscriptions): int
    {
        $user = User::query()->where('email', (string) $this->argument('user'))
            ->orWhere('user_id', (string) $this->argument('user'))->first();
        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }
        $action = (string) $this->argument('action');
        if ($action === 'list') {
            $rows = MarketSubscription::query()->with('market.exchange')->where('user_id', $user->user_id)->get();
            $this->table(['Exchange', 'Symbol', 'Tick size', 'Active'], $rows->map(fn (MarketSubscription $item): array =>
                [$item->market->exchange->class, $item->market->symbol, $item->market->tick_size, $item->active ? 'yes' : 'no'])->all());

            return self::SUCCESS;
        }
        if (! in_array($action, ['subscribe', 'unsubscribe'], true) || ! $this->argument('exchange') || ! $this->argument('symbol')) {
            $this->error('Use subscribe/unsubscribe with an exchange ID and a symbol, or list with a user.');

            return self::FAILURE;
        }
        $matches = Exchange::query()->where('class', (string) $this->argument('exchange'))->limit(2)->get();
        if ($matches->count() !== 1) {
            $this->error('Exchange is missing or its CCXT ID is duplicated.');

            return self::FAILURE;
        }
        $exchange = $matches->first();
        $symbol = (string) $this->argument('symbol');
        try {
            if ($action === 'subscribe') {
                $tickSize = (string) $this->option('tick-size');
                if ($tickSize === '') {
                    throw new InvalidArgumentException('Supply --tick-size for a new or existing market.');
                }
                $subscription = $subscriptions->subscribe($user, $exchange, $symbol, $tickSize);
                $this->info("Subscription active for {$subscription->market_id}.");
            } else {
                $market = Market::query()->where('exchange_id', $exchange->exchange_id)->where('symbol', $symbol)->first();
                if ($market === null) {
                    throw new InvalidArgumentException('Market not found.');
                }
                $subscriptions->unsubscribe($user, $market);
                $this->info('Subscription inactive.');
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
