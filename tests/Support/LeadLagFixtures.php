<?php

namespace Tests\Support;

use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Support\Str;

final class LeadLagFixtures
{
    public static function noise(int $i, string $stream = 'leader'): float
    {
        return (hexdec(substr(hash('sha256', $stream.':'.$i), 0, 8)) / 4294967295 - 0.5) * 0.02;
    }

    public static function series(int $count = 1600, int $lag = 3, float $direction = 1.0): array
    {
        $leader = $follower = [];
        for ($i = 0; $i < $count; $i++) {
            $at = IntelligenceFixtures::START + ($i + 1) * 60000;
            $leader[$at] = ['return' => self::noise($i), 'volume' => 100, 'range' => 0.03];
            $follower[$at] = ['return' => $direction * self::noise($i - $lag) + 0.03 * self::noise($i, 'noise'), 'volume' => 100, 'range' => 0.03];
        }

        return [$leader, $follower];
    }

    public static function market(string $class, string $period = '1m', string $symbol = 'BTC/USD'): MarketSubscription
    {
        $exchange = Exchange::query()->create(['name' => $class, 'class' => $class, 'config' => '{}']);
        $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);
        MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => $period, 'status' => 'active']);

        return MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    }

    public static function candles(int $count = 1000): void
    {
        foreach (['bitso' => 0, 'kraken' => 3] as $exchange => $lag) {
            $close = 100.0;
            $batch = [];
            for ($i = -1800; $i < $count; $i++) {
                $open = $close;
                $close *= exp(self::noise($i - $lag));
                $batch[] = ['ticker_id' => (string) Str::uuid7(), 'exchange' => $exchange, 'symbol' => 'BTC/USD', 'period' => '1m',
                    'microtimestamp' => IntelligenceFixtures::START + $i * 60000,
                    'payload' => json_encode(['open' => $open, 'close' => $close, 'high' => max($open, $close) * 1.001,
                        'low' => min($open, $close) * 0.999, 'volume' => 100]), 'created_at' => now(), 'updated_at' => now()];
                if (count($batch) === 100) {
                    Ticker::query()->insert($batch);
                    $batch = [];
                }
            }
            Ticker::query()->insert($batch);
        }
    }
}
