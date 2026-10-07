<?php

namespace App\Domain\Features;

use App\Domain\Operations\ActionLog;
use App\Models\CoinGeckoMarketMapping;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CoinGeckoCollector
{
    public function __construct(
        private readonly CoinGeckoClient $client,
        private readonly CoinGeckoMappingManager $mappings,
    ) {}

    public function collect(): int
    {
        if (! config('features.coingecko.enabled')) {
            return 0;
        }
        if (! config('features.coingecko.api_key')) {
            throw new RuntimeException('Set COINGECKO_API_KEY before enabling context collection.');
        }

        // Backfill deployments that already had subscriptions before the mapping table existed,
        // then resolve newly-created pending mappings. New subscriptions are also fed immediately
        // by MarketSubscriptionCreated -> EnsureCoinGeckoMarketMapping.
        $this->mappings->ensureForActiveMarkets();
        $this->mappings->resolvePending();

        $mappings = CoinGeckoMarketMapping::query()
            ->with('market')
            ->where('status', 'resolved')
            ->whereNotNull('coin_id')
            ->whereNotNull('vs_currency')
            ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))
            ->get()
            ->map(fn (CoinGeckoMarketMapping $mapping): array => [
                'id' => $mapping->coin_id,
                'vs_currency' => strtolower((string) $mapping->vs_currency),
                'category' => $mapping->category,
                'symbol' => $mapping->market?->symbol,
            ])
            ->all();

        return $this->collectMappings($mappings);
    }

    public function fetchForMapping(CoinGeckoMarketMapping $mapping): int
    {
        if (! config('features.coingecko.enabled')) {
            throw new RuntimeException('Set COINGECKO_ENABLED=true before fetching context.');
        }
        if (! config('features.coingecko.api_key')) {
            throw new RuntimeException('Set COINGECKO_API_KEY before fetching context.');
        }
        if ($mapping->status !== 'resolved' || ! $mapping->coin_id || ! $mapping->vs_currency) {
            throw new RuntimeException('A resolved CoinGecko mapping is required.');
        }
        $mapping->loadMissing('market');

        return $this->collectMappings([[
            'id' => $mapping->coin_id,
            'vs_currency' => strtolower((string) $mapping->vs_currency),
            'category' => $mapping->category,
            'symbol' => $mapping->market?->symbol,
        ]], force: true);
    }

    private function collectMappings(array $mappings, bool $force = false): int
    {
        $hourStart = intdiv(now()->getTimestampMs(), 3600000) * 3600000;
        if (! $force) {
            $mappings = array_values(array_filter($mappings, fn ($mapping): bool => ! DB::table('market_context_snapshots')
                ->where('coin_id', $mapping['id'] ?? '')
                ->where('vs_currency', strtolower($mapping['vs_currency'] ?? ''))
                ->where('observed_at_ms', '>=', $hourStart)
                ->exists()));
        }
        if ($mappings === []) {
            return 0;
        }

        $groups = [];
        foreach ($mappings as $mapping) {
            $currency = strtolower($mapping['vs_currency'] ?? '');
            $quote = strtolower(explode('/', (string) ($mapping['symbol'] ?? ''), 2)[1] ?? '');
            if (! ($mapping['id'] ?? null) || $currency === '' || $quote !== $currency) {
                throw new RuntimeException('CoinGecko mappings require a coin ID and the exact spot quote currency.');
            }
            $groups[$currency][] = $mapping['id'];
        }

        $global = $this->client->get('/global')['data'] ?? null;
        if (! is_array($global) || ! isset($global['updated_at']) || abs(now()->getTimestamp() - (int) $global['updated_at']) > config('features.coingecko.max_age_seconds')) {
            throw new RuntimeException('Missing or stale CoinGecko global data.');
        }

        $categories = [];
        $categoryExpires = [];
        if (array_filter(array_column($mappings, 'category'))) {
            foreach ($this->client->get('/coins/categories') as $category) {
                if (isset($category['id'], $category['updated_at']) && abs(now()->getTimestamp() - strtotime($category['updated_at'])) <= config('features.coingecko.max_age_seconds')) {
                    $categories[$category['id']] = $category['market_cap_change_24h'] ?? null;
                    $categoryExpires[$category['id']] = (strtotime($category['updated_at']) + config('features.coingecko.max_age_seconds')) * 1000;
                }
            }
        }

        $saved = 0;
        foreach ($groups as $currency => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), 100) as $batch) {
                try {
                    $coins = $this->client->get('/coins/markets', [
                        'vs_currency' => $currency,
                        'ids' => implode(',', $batch),
                        'per_page' => 100,
                        'page' => 1,
                        'sparkline' => 'false',
                    ]);
                } catch (RequestException $exception) {
                    // Recover legacy mappings resolved before quote validation.
                    // All unrelated provider errors must still propagate.
                    if ($exception->response->status() !== 400
                        || $exception->response->json('error') !== 'invalid vs_currency') {
                        throw $exception;
                    }

                    CoinGeckoMarketMapping::query()
                        ->where('status', 'resolved')
                        ->whereRaw('LOWER(vs_currency) = ?', [$currency])
                        ->update([
                            'status' => 'unsupported',
                            'last_error' => 'CoinGecko rejected the exact quote currency '.strtoupper($currency).' for /coins/markets.',
                            'updated_at' => now(),
                        ]);

                    continue;
                }
                foreach ($coins as $coin) {
                    if (! in_array($coin['id'] ?? null, $batch, true) || ! isset($coin['last_updated']) || abs(now()->getTimestamp() - strtotime($coin['last_updated'])) > config('features.coingecko.max_age_seconds')) {
                        continue;
                    }
                    $at = now()->getTimestampMs(); // Availability is receipt time, never provider time.
                    $history = DB::table('market_context_snapshots')->where('coin_id', $coin['id'])->where('vs_currency', $currency)
                        ->where('observed_at_ms', '<', intdiv($at, 3600000) * 3600000)
                        ->orderByDesc('observed_at_ms')->orderByDesc('snapshot_id')->lazy(168);
                    $activity = FeatureEngine::ratio($coin['total_volume'] ?? null, $coin['market_cap'] ?? null);
                    $ratios = [];
                    $priorDominance = null;
                    $hours = [];
                    foreach ($history as $row) {
                        $old = null;
                        if ($priorDominance === null && $row->observed_at_ms <= $at - 86400000 && $row->observed_at_ms >= $at - 86400000 - 7200000) {
                            $old = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                            $priorDominance = $old['global']['market_cap_percentage']['btc'] ?? null;
                        }
                        $hour = intdiv((int) $row->observed_at_ms, 3600000);
                        if (isset($hours[$hour])) {
                            continue;
                        }
                        // Forced refreshes must not create fake hourly history or
                        // crowd the 24-hour dominance anchor out of the window.
                        $hours[$hour] = true;
                        $old ??= json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                        $r = FeatureEngine::ratio($old['coin']['total_volume'] ?? null, $old['coin']['market_cap'] ?? null);
                        if ($r !== null) {
                            $ratios[] = $r;
                        }
                        if (count($hours) >= 168) {
                            break;
                        }
                    }
                    $deviation = null;
                    if (count($ratios) >= 24 && $activity !== null) {
                        $mean = array_sum($ratios) / count($ratios);
                        $sd = sqrt(array_sum(array_map(fn ($r) => ($r - $mean) ** 2, $ratios)) / count($ratios));
                        $deviation = $sd == 0 ? ($activity == $mean ? 0.0 : ($activity <=> $mean) * 10.0) : ($activity - $mean) / $sd;
                    }
                    $dominance = $global['market_cap_percentage']['btc'] ?? null;
                    DB::table('market_context_snapshots')->insert([
                        'snapshot_id' => (string) Str::uuid7(),
                        'coin_id' => $coin['id'],
                        'vs_currency' => $currency,
                        'observed_at_ms' => $at,
                        'payload' => json_encode([
                            'coin' => $coin,
                            'global' => $global,
                            'categories' => $categories,
                            'category_expires_at_ms' => $categoryExpires,
                            'expires_at_ms' => min((int) $global['updated_at'], strtotime($coin['last_updated'])) * 1000 + config('features.coingecko.max_age_seconds') * 1000,
                            'activity_deviation' => $deviation,
                            'btc_dominance_change' => $priorDominance === null || $dominance === null ? null : $dominance - $priorDominance,
                        ], JSON_THROW_ON_ERROR),
                    ]);
                    $saved++;
                }
            }
        }

        app(ActionLog::class)->write('context.collected', ['rows' => $saved, 'outcome' => 'completed']);

        return $saved;
    }
}
