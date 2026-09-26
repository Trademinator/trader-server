<?php

namespace App\Domain\Features;

use App\Models\CoinGeckoMarketMapping;
use App\Models\Market;
use Illuminate\Support\Collection;
use Throwable;

final class CoinGeckoMappingManager
{
    private ?array $categoryIdsByName = null;

    public function __construct(private readonly CoinGeckoClient $client) {}

    public function ensureForMarket(Market $market): CoinGeckoMarketMapping
    {
        [$base, $quote] = $this->splitSpotSymbol($market->symbol);

        return CoinGeckoMarketMapping::query()->firstOrCreate(
            ['market_id' => $market->market_id],
            [
                'base_symbol' => $base,
                'vs_currency' => $quote,
                'status' => $base !== null && $quote !== null ? 'pending' : 'unsupported',
                'last_error' => $base !== null && $quote !== null ? null : 'Only BASE/QUOTE spot symbols can be mapped automatically.',
            ],
        );
    }

    public function ensureForActiveMarkets(): int
    {
        $created = 0;

        Market::query()
            ->whereHas('subscriptions', fn ($query) => $query->where('active', true))
            ->orderBy('market_id')
            ->lazyById(100, column: 'market_id')
            ->each(function (Market $market) use (&$created): void {
                $mapping = $this->ensureForMarket($market);
                if ($mapping->wasRecentlyCreated) {
                    $created++;
                }
            });

        return $created;
    }

    public function resolvePending(int $limit = 100): array
    {
        if (! config('features.coingecko.enabled')) {
            return ['resolved' => 0, 'ambiguous' => 0, 'unmapped' => 0];
        }

        $pending = CoinGeckoMarketMapping::query()
            ->where('status', 'pending')
            ->whereNotNull('base_symbol')
            ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))
            ->orderBy('created_at')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->groupBy(fn (CoinGeckoMarketMapping $mapping): string => strtoupper((string) $mapping->base_symbol));

        $counts = ['resolved' => 0, 'ambiguous' => 0, 'unmapped' => 0];

        foreach ($pending as $symbol => $mappings) {
            $response = $this->client->get('/search', ['query' => $symbol]);
            $coins = collect($response['coins'] ?? [])
                ->filter(fn ($coin): bool => is_array($coin)
                    && isset($coin['id'], $coin['symbol'])
                    && strcasecmp((string) $coin['symbol'], $symbol) === 0)
                ->values();

            if ($coins->count() !== 1) {
                $status = $coins->isEmpty() ? 'unmapped' : 'ambiguous';
                $error = $coins->isEmpty()
                    ? 'CoinGecko returned no exact symbol match.'
                    : 'CoinGecko returned multiple exact symbol matches; manual mapping is required.';
                $this->markGroup($mappings, $status, $error);
                $counts[$status] += $mappings->count();

                continue;
            }

            $coin = $coins->first();
            $category = $this->resolvePrimaryCategory((string) $coin['id']);
            foreach ($mappings as $mapping) {
                $mapping->update([
                    'coin_id' => (string) $coin['id'],
                    'coin_name' => isset($coin['name']) ? (string) $coin['name'] : null,
                    'category' => $category,
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'last_error' => null,
                ]);
                $counts['resolved']++;
            }
        }

        return $counts;
    }

    private function splitSpotSymbol(string $symbol): array
    {
        if (substr_count($symbol, '/') !== 1 || str_contains($symbol, ':')) {
            return [null, null];
        }

        [$base, $quote] = array_map('trim', explode('/', $symbol, 2));
        if ($base === '' || $quote === '') {
            return [null, null];
        }

        return [strtoupper($base), strtolower($quote)];
    }

    private function markGroup(Collection $mappings, string $status, string $error): void
    {
        foreach ($mappings as $mapping) {
            $mapping->update(['status' => $status, 'last_error' => $error]);
        }
    }

    private function resolvePrimaryCategory(string $coinId): ?string
    {
        try {
            $coin = $this->client->get('/coins/'.rawurlencode($coinId), [
                'localization' => 'false',
                'tickers' => 'false',
                'market_data' => 'false',
                'community_data' => 'false',
                'developer_data' => 'false',
                'sparkline' => 'false',
            ]);
            $names = array_values(array_filter($coin['categories'] ?? [], 'is_string'));
            if ($names === []) {
                return null;
            }

            $index = $this->categoryIdsByName();
            foreach ($names as $name) {
                $id = $index[mb_strtolower(trim($name))] ?? null;
                if ($id !== null) {
                    return $id;
                }
            }
        } catch (Throwable) {
            // Category momentum is optional context. A metadata lookup failure must not block
            // an otherwise unambiguous coin mapping or the rest of the CoinGecko context.
        }

        return null;
    }

    private function categoryIdsByName(): array
    {
        if ($this->categoryIdsByName !== null) {
            return $this->categoryIdsByName;
        }

        $this->categoryIdsByName = [];
        foreach ($this->client->get('/coins/categories/list') as $category) {
            if (is_array($category) && isset($category['category_id'], $category['name'])) {
                $this->categoryIdsByName[mb_strtolower(trim((string) $category['name']))] = (string) $category['category_id'];
            }
        }

        return $this->categoryIdsByName;
    }
}
