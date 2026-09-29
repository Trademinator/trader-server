<?php

namespace App\Domain\MarketSuggestions;

use App\Domain\Features\CoinGeckoClient;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class MarketDiscovery
{
    public const CACHE_KEY = 'trademinator:market-discovery:v1';

    public function __construct(private CoinGeckoClient $client) {}

    public function snapshot(): ?array
    {
        if (! config('dashboard.discovery_enabled') || ! config('features.coingecko.enabled')) {
            return null;
        }
        $snapshot = Cache::get(self::CACHE_KEY);

        return is_array($snapshot) && ($snapshot['expires_at_ms'] ?? 0) > now()->getTimestampMs() ? $snapshot : null;
    }

    /** A bounded public discovery list; it neither creates subscriptions nor feeds training history. */
    public function refresh(): int
    {
        if (! config('dashboard.discovery_enabled') || ! config('features.coingecko.enabled')) {
            return 0;
        }
        if (! config('features.coingecko.api_key')) {
            throw new RuntimeException('CoinGecko discovery requires COINGECKO_API_KEY.');
        }
        $now = now()->getTimestampMs();
        $maxAge = (int) config('dashboard.discovery_max_age_seconds') * 1000;
        $global = $this->client->get('/global')['data'] ?? [];
        $globalTime = (int) ($global['updated_at'] ?? 0) * 1000;
        if ($globalTime > $now + 60000 || $globalTime < $now - $maxAge) {
            throw new RuntimeException('CoinGecko global context is missing or stale.');
        }
        $coins = $this->client->get('/coins/markets', ['vs_currency' => 'usd', 'order' => 'volume_desc',
            'per_page' => 100, 'page' => 1, 'sparkline' => 'false', 'price_change_percentage' => '24h,7d']);
        $eligible = [];
        foreach (array_slice($coins, 0, 100) as $coin) {
            $at = strtotime((string) ($coin['last_updated'] ?? ''));
            $symbol = strtoupper((string) ($coin['symbol'] ?? ''));
            $volume = $this->number($coin['total_volume'] ?? null);
            $cap = $this->number($coin['market_cap'] ?? null);
            if (! isset($coin['id']) || ! preg_match('/^[A-Z0-9._-]{1,20}$/D', $symbol)
                || $at === false || $at * 1000 > $now + 60000 || $at * 1000 < $now - $maxAge
                || $volume === null || $cap === null || $volume < 0 || $cap <= 0) {
                continue;
            }
            $eligible[] = ['coin_id' => $coin['id'], 'symbol' => $symbol, 'activity_ratio' => $volume / $cap,
                'volume_usd' => $volume, 'market_cap_usd' => $cap,
                'change_24h' => $this->number($coin['price_change_percentage_24h'] ?? null),
                'source_at_ms' => $at * 1000];
        }
        usort($eligible, fn (array $a, array $b): int => ($b['activity_ratio'] <=> $a['activity_ratio']) ?: strcmp($a['coin_id'], $b['coin_id']));
        $resolved = [];
        $limit = max(1, min(20, (int) config('dashboard.discovery_limit')));
        foreach (array_slice($eligible, 0, $limit) as $coin) {
            $matches = array_values(array_filter($this->client->get('/search', ['query' => $coin['symbol']])['coins'] ?? [],
                fn (array $match): bool => strcasecmp((string) ($match['symbol'] ?? ''), $coin['symbol']) === 0));
            if (count($matches) === 1 && ($matches[0]['id'] ?? null) === $coin['coin_id']) {
                $resolved[$coin['symbol']] = $coin;
            }
        }
        $categories = [];
        foreach ($this->client->get('/coins/categories') as $category) {
            $at = strtotime((string) ($category['updated_at'] ?? ''));
            $change = $this->number($category['market_cap_change_24h'] ?? null);
            if ($at !== false && $at * 1000 <= $now + 60000 && $at * 1000 >= $now - $maxAge && $change !== null) {
                $categories[] = ['name' => mb_substr((string) ($category['name'] ?? ''), 0, 100), 'change_24h' => $change,
                    'source_at_ms' => $at * 1000];
            }
        }
        usort($categories, fn (array $a, array $b): int => abs($b['change_24h']) <=> abs($a['change_24h']));
        $categories = array_slice($categories, 0, 5);
        $expires = $globalTime + $maxAge;
        foreach (array_merge(array_values($resolved), $categories) as $source) {
            $expires = min($expires, $source['source_at_ms'] + $maxAge);
        }
        Cache::put(self::CACHE_KEY, [
            'observed_at_ms' => now()->getTimestampMs(), 'expires_at_ms' => $expires, 'coins' => $resolved,
            'market_change_24h' => $this->number($global['market_cap_change_percentage_24h_usd'] ?? null),
            'btc_dominance' => $this->number($global['market_cap_percentage']['btc'] ?? null),
            'volume_usd' => $this->number($global['total_volume']['usd'] ?? null),
            'categories' => $categories,
        ], now()->addSeconds((int) config('dashboard.discovery_max_age_seconds')));

        return count($resolved);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
