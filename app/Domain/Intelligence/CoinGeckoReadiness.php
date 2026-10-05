<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\ContextFeatures;
use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\FeatureSchema;
use App\Models\Market;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

final class CoinGeckoReadiness
{
    public function __construct(private SignalFreshness $freshness) {}

    /** @param iterable<Market> $markets Markets with exchange and feed already loaded. */
    public function forMarkets(iterable $markets): Collection
    {
        return $this->forStreams(collect($markets)->map(fn (Market $market): array => [
            'exchange' => $market->exchange->class, 'symbol' => $market->symbol,
            'period' => $market->feed?->selected_period ?? '',
        ]));
    }

    /**
     * Read current context independently of model builds, in bounded batches.
     *
     * @param  iterable<array>  $streams  Exchange, symbol and period tuples (model reports are also accepted).
     * @return Collection<string, array{ready: bool, reason: string}>
     */
    public function forStreams(iterable $streams): Collection
    {
        $streams = collect($streams)->filter(fn (array $stream): bool => ! empty($stream['exchange']) && ! empty($stream['symbol']))
            ->mapWithKeys(fn (array $stream): array => [ModelStore::marketKey($stream['exchange'], $stream['symbol'], $stream['period'] ?? '') => [
                'exchange' => $stream['exchange'], 'symbol' => $stream['symbol'], 'period' => $stream['period'] ?? '',
            ]]);
        $result = $streams->map(fn (): array => ['ready' => false, 'reason' => 'coingecko_disabled']);
        if (! config('features.coingecko.enabled')) {
            return $result;
        }
        $now = now()->getTimestampMs();
        foreach ($streams->chunk(100) as $batch) {
            $mappings = DB::table('coin_gecko_market_mappings as mappings')
                ->join('markets', 'markets.market_id', '=', 'mappings.market_id')
                ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
                ->where(function ($query) use ($batch): void {
                    foreach ($batch as $stream) {
                        $query->orWhere(fn ($pair) => $pair->where('exchanges.class', $stream['exchange'])->where('markets.symbol', $stream['symbol']));
                    }
                })->get(['exchanges.class as exchange', 'markets.symbol', 'mappings.status', 'mappings.coin_id', 'mappings.vs_currency', 'mappings.category'])
                ->keyBy(fn ($mapping): string => ModelStore::marketKey($mapping->exchange, $mapping->symbol, ''));

            $query = null;
            foreach ($batch as $stream) {
                if (! in_array($stream['period'], CandleTimeframe::SUPPORTED, true)) {
                    continue;
                }
                // One indexed latest-row lookup per stream; never load its feature history.
                $latest = DB::table('market_features')->select('exchange', 'symbol', 'period', 'available_at_ms', 'payload')
                    ->where('exchange', $stream['exchange'])->where('symbol', $stream['symbol'])->where('period', $stream['period'])
                    ->where('version', FeatureEngine::VERSION)->where('available_at_ms', '<=', $now)
                    ->orderByDesc('available_at_ms')->orderByDesc('microtimestamp')->limit(1);
                $branch = DB::query()->fromSub($latest, 'latest_features');
                $query = $query === null ? $branch : $query->unionAll($branch);
            }
            $features = ($query?->get() ?? collect())->mapWithKeys(function ($row): array {
                try {
                    $row->payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $row->payload = [];
                }

                return [ModelStore::marketKey($row->exchange, $row->symbol, $row->period) => $row];
            });
            $snapshotIds = $features->pluck('payload.context_snapshot_id')->filter()->unique()->values();
            $snapshots = $snapshotIds->isEmpty() ? collect() : DB::table('market_context_snapshots')
                ->whereIn('snapshot_id', $snapshotIds)->get()->keyBy('snapshot_id');
            foreach ($batch as $key => $stream) {
                $feature = $features->get($key);
                $reason = $this->reason($stream, $feature,
                    $mappings->get(ModelStore::marketKey($stream['exchange'], $stream['symbol'], '')),
                    $snapshots->get($feature->payload['context_snapshot_id'] ?? ''), $now);
                $result->put($key, ['ready' => $reason === 'ready', 'reason' => $reason]);
            }
        }

        return $result;
    }

    private function reason(array $stream, ?object $feature, ?object $mapping, ?object $snapshot, int $now): string
    {
        if (! in_array($stream['period'], CandleTimeframe::SUPPORTED, true)) {
            return 'coingecko_period_pending';
        }
        if ($mapping === null || $mapping->status !== 'resolved' || ! $mapping->coin_id) {
            return 'coingecko_mapping_unresolved';
        }
        if (strtolower(explode('/', $stream['symbol'], 2)[1] ?? '') !== strtolower((string) $mapping->vs_currency)) {
            return 'coingecko_quote_mismatch';
        }
        if ($feature === null) {
            return 'coingecko_features_unavailable';
        }
        if (($this->freshness->expiresAt((int) $feature->available_at_ms, $stream['period']) ?? 0) <= $now) {
            return 'coingecko_features_stale';
        }
        $payload = $feature->payload;
        if (($payload['version'] ?? null) !== FeatureEngine::VERSION
            || ($payload['available_at_ms'] ?? null) !== (int) $feature->available_at_ms) {
            return 'coingecko_context_invalid';
        }
        try {
            if (FeatureSchema::vector($payload, ContextFeatures::KEYS) === null) {
                return 'coingecko_context_incomplete';
            }
        } catch (InvalidArgumentException) {
            return 'coingecko_context_invalid';
        }
        if ($snapshot === null || $snapshot->coin_id !== $mapping->coin_id
            || strtolower($snapshot->vs_currency) !== strtolower($mapping->vs_currency)) {
            return 'coingecko_snapshot_unavailable';
        }
        try {
            $source = json_decode($snapshot->payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'coingecko_context_invalid';
        }
        if ($snapshot->observed_at_ms > $feature->available_at_ms
            || $snapshot->observed_at_ms < $now - max(0, (int) config('features.coingecko.max_age_seconds')) * 1000
            || ($source['expires_at_ms'] ?? 0) < $now
            || ($source['category_expires_at_ms'][$mapping->category ?? ''] ?? 0) < $now) {
            return 'coingecko_context_stale';
        }

        return 'ready';
    }
}
