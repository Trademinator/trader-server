<?php

namespace App\Domain\MarketData;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Short-lived shared cache for closed ticker history.
 *
 * A market-period generation token invalidates every cached range/page without
 * enumerating keys. Old entries expire naturally after the short TTL.
 */
final class TickerHistoryCache
{
    public function shouldCacheRange(string $period, int $fromMs, int $toMs): bool
    {
        if (! $this->enabled() || $fromMs < 0 || $toMs < $fromMs) {
            return false;
        }

        $maxRows = (int) config('market_data.history_cache.max_rows', 2000);
        $timeframe = new CandleTimeframe;
        $cursor = $fromMs;

        try {
            for ($rows = 0; $rows <= $maxRows; $rows++) {
                if ($cursor > $toMs) {
                    return true;
                }

                $next = $timeframe->next($cursor, $period);
                if ($next <= $cursor) {
                    return false;
                }

                $cursor = $next;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public function range(string $exchange, string $symbol, string $period, int $fromMs, int $toMs): ?array
    {
        $value = $this->read($exchange, $symbol, $period, 'range:'.$fromMs.':'.$toMs);

        return is_array($value) ? $value : null;
    }

    public function putRange(string $exchange, string $symbol, string $period, int $fromMs, int $toMs, array $rows): void
    {
        $this->write($exchange, $symbol, $period, 'range:'.$fromMs.':'.$toMs, $rows);
    }

    public function metadata(string $exchange, string $symbol, string $period, string $scope): mixed
    {
        return $this->read($exchange, $symbol, $period, 'meta:'.$scope);
    }

    public function putMetadata(string $exchange, string $symbol, string $period, string $scope, mixed $value): void
    {
        $this->write($exchange, $symbol, $period, 'meta:'.$scope, $value);
    }

    public function invalidate(string $exchange, string $symbol, string $period): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $this->store()->forever($this->generationKey($exchange, $symbol, $period), (string) Str::uuid7());
        } catch (Throwable) {
            // Cache is an optimization. Authoritative DB/archive reads remain available.
        }
    }

    private function read(string $exchange, string $symbol, string $period, string $scope): mixed
    {
        $generation = $this->generation($exchange, $symbol, $period);
        if ($generation === null) {
            return null;
        }

        try {
            return $this->store()->get($this->valueKey($exchange, $symbol, $period, $generation, $scope));
        } catch (Throwable) {
            return null;
        }
    }

    private function write(string $exchange, string $symbol, string $period, string $scope, mixed $value): void
    {
        $generation = $this->generation($exchange, $symbol, $period);
        if ($generation === null) {
            return;
        }

        try {
            $this->store()->put(
                $this->valueKey($exchange, $symbol, $period, $generation, $scope),
                $value,
                now()->addSeconds((int) config('market_data.history_cache.ttl_seconds', 30))
            );
        } catch (Throwable) {
            // Cache is fail-open by design.
        }
    }

    private function generation(string $exchange, string $symbol, string $period): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $store = $this->store();
            $key = $this->generationKey($exchange, $symbol, $period);
            $generation = $store->get($key);

            if (! is_string($generation) || $generation === '') {
                $generation = (string) Str::uuid7();
                $store->forever($key, $generation);
            }

            return $generation;
        } catch (Throwable) {
            return null;
        }
    }

    private function enabled(): bool
    {
        return (bool) config('market_data.history_cache.enabled', true);
    }

    private function store(): Repository
    {
        return Cache::store((string) config('market_data.history_cache.store', 'redis'));
    }

    private function generationKey(string $exchange, string $symbol, string $period): string
    {
        return 'ticker-history:g:'.hash('sha256', $exchange."\0".$symbol."\0".$period);
    }

    private function valueKey(
        string $exchange,
        string $symbol,
        string $period,
        string $generation,
        string $scope
    ): string {
        return 'ticker-history:v1:'.hash('sha256', $exchange."\0".$symbol."\0".$period)
            .':'.$generation.':'.hash('sha256', $scope);
    }
}
