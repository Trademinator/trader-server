<?php

namespace App\Domain\Features;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class CoinGeckoCollector
{
    private function get(string $path, array $query = []): array
    {
        $pro = config('features.coingecko.pro');
        $response = Http::baseUrl($pro ? 'https://pro-api.coingecko.com/api/v3' : 'https://api.coingecko.com/api/v3')
            ->acceptJson()->withHeaders([$pro ? 'x-cg-pro-api-key' : 'x-cg-demo-api-key' => config('features.coingecko.api_key')])
            ->connectTimeout(10)->timeout(30)->get($path, $query)->throw()->json();
        if (! is_array($response)) {
            throw new RuntimeException('Invalid CoinGecko response.');
        }

        return $response;
    }

    public function collect(): int
    {
        if (! config('features.coingecko.enabled')) {
            return 0;
        }
        if (! config('features.coingecko.api_key')) {
            throw new RuntimeException('Set COINGECKO_API_KEY before enabling context collection.');
        }
        $mappings = config('features.coingecko.markets', []);
        $hourStart = intdiv((int) floor(microtime(true) * 1000), 3600000) * 3600000;
        $mappings = array_filter($mappings, fn ($m) => ! DB::table('market_context_snapshots')
            ->where('coin_id', $m['id'] ?? '')->where('vs_currency', strtolower($m['vs_currency'] ?? ''))
            ->where('observed_at_ms', '>=', $hourStart)->exists());
        if (! $mappings) {
            return 0;
        }
        $global = $this->get('/global')['data'] ?? null;
        if (! is_array($global) || ! isset($global['updated_at']) || abs(time() - (int) $global['updated_at']) > config('features.coingecko.max_age_seconds')) {
            throw new RuntimeException('Missing or stale CoinGecko global data.');
        }
        $categories = [];
        $categoryExpires = [];
        if (array_filter(array_column($mappings, 'category'))) {
            foreach ($this->get('/coins/categories') as $category) {
                if (isset($category['id'], $category['updated_at']) && abs(time() - strtotime($category['updated_at'])) <= config('features.coingecko.max_age_seconds')) {
                    $categories[$category['id']] = $category['market_cap_change_24h'] ?? null;
                    $categoryExpires[$category['id']] = (strtotime($category['updated_at']) + config('features.coingecko.max_age_seconds')) * 1000;
                }
            }
        }
        $groups = [];
        foreach ($mappings as $key => $mapping) {
            $currency = strtolower($mapping['vs_currency'] ?? '');
            $quote = strtolower(explode('/', explode(':', $key, 2)[1] ?? '')[1] ?? '');
            if (! ($mapping['id'] ?? null) || $currency === '' || $quote !== $currency) {
                throw new RuntimeException('CoinGecko mappings require a coin ID and the exact spot quote currency: '.$key);
            }
            $groups[$currency][] = $mapping['id'];
        }
        $saved = 0;
        foreach ($groups as $currency => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), 100) as $batch) {
                $coins = $this->get('/coins/markets', ['vs_currency' => $currency, 'ids' => implode(',', $batch), 'per_page' => 100, 'page' => 1, 'sparkline' => 'false']);
                foreach ($coins as $coin) {
                    if (! in_array($coin['id'] ?? null, $batch, true) || ! isset($coin['last_updated']) || abs(time() - strtotime($coin['last_updated'])) > config('features.coingecko.max_age_seconds')) {
                        continue;
                    }
                    $at = (int) floor(microtime(true) * 1000); // Availability is receipt time, never provider time.
                    $history = DB::table('market_context_snapshots')->where('coin_id', $coin['id'])->where('vs_currency', $currency)
                        ->where('observed_at_ms', '<', $at)->orderByDesc('observed_at_ms')->limit(168)->get();
                    $activity = FeatureEngine::ratio($coin['total_volume'] ?? null, $coin['market_cap'] ?? null);
                    $ratios = [];
                    $priorDominance = null;
                    foreach ($history as $row) {
                        $old = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                        $r = FeatureEngine::ratio($old['coin']['total_volume'] ?? null, $old['coin']['market_cap'] ?? null);
                        if ($r !== null) {
                            $ratios[] = $r;
                        }
                        if ($priorDominance === null && $row->observed_at_ms <= $at - 86400000 && $row->observed_at_ms >= $at - 86400000 - 7200000) {
                            $priorDominance = $old['global']['market_cap_percentage']['btc'] ?? null;
                        }
                    }
                    $deviation = null;
                    if (count($ratios) >= 24 && $activity !== null) {
                        $mean = array_sum($ratios) / count($ratios);
                        $sd = sqrt(array_sum(array_map(fn ($r) => ($r - $mean) ** 2, $ratios)) / count($ratios));
                        $deviation = $sd == 0 ? ($activity == $mean ? 0.0 : ($activity <=> $mean) * 10.0) : ($activity - $mean) / $sd;
                    }
                    $dominance = $global['market_cap_percentage']['btc'] ?? null;
                    DB::table('market_context_snapshots')->insert(['snapshot_id' => (string) Str::uuid7(), 'coin_id' => $coin['id'], 'vs_currency' => $currency, 'observed_at_ms' => $at,
                        'payload' => json_encode(['coin' => $coin, 'global' => $global, 'categories' => $categories, 'category_expires_at_ms' => $categoryExpires,
                            'expires_at_ms' => min((int) $global['updated_at'], strtotime($coin['last_updated'])) * 1000 + config('features.coingecko.max_age_seconds') * 1000,
                            'activity_deviation' => $deviation, 'btc_dominance_change' => $priorDominance === null || $dominance === null ? null : $dominance - $priorDominance], JSON_THROW_ON_ERROR)]);
                    $saved++;
                }
            }
        }

        return $saved;
    }
}
