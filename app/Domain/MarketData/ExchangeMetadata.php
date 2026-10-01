<?php

namespace App\Domain\MarketData;

use App\Models\Exchange;
use App\Models\User;
use Composer\InstalledVersions;

/** Small offline descriptions; never instantiate every adapter in a web worker. */
class ExchangeMetadata
{
    public function runtimePath(): string
    {
        return storage_path('app/private/ccxt-exchanges.json');
    }

    public function all(): array
    {
        $reviewPath = resource_path('data/ccxt-access-reviews.json');
        $reviewHash = is_file($reviewPath) ? hash_file('sha256', $reviewPath) : null;
        $data = null;
        // Preserved storage from a previous deployment must not shadow a current bundle.
        foreach ([$this->runtimePath(), resource_path('data/ccxt-exchanges.json')] as $path) {
            $candidate = ExchangeMetadataBuilder::read($path);
            if (($candidate['schema'] ?? null) === ExchangeMetadataBuilder::SCHEMA
                && ($candidate['ccxt_reference'] ?? null) === InstalledVersions::getReference('ccxt/ccxt')
                && ($candidate['ccxt_version'] ?? null) === InstalledVersions::getPrettyVersion('ccxt/ccxt')
                && $reviewHash !== null && ($candidate['reviews_sha256'] ?? null) === $reviewHash
                && is_array($candidate['exchanges'] ?? null)) {
                $data = $candidate;
                break;
            }
        }
        if ($data === null) {
            throw new MarketCatalogException('metadata_stale',
                'The exchange list needs a server update. Ask the administrator to run trademinator:refresh-exchanges.', 503);
        }
        $root = InstalledVersions::getInstallPath('ccxt/ccxt');
        $hashes = $entries = [];
        foreach (\ccxt\Exchange::$exchanges as $id) {
            $entry = $data['exchanges'][$id] ?? ['name' => $id];
            if (! isset($data['exchanges'][$id])) {
                $entry['access'] = ExchangeMetadataBuilder::unknown('not_reviewed');
            } elseif (! CcxtAdapterInspector::matches($entry['source_files'] ?? [], $root, $hashes)) {
                $reason = isset($entry['source_files']) ? 'source_changed' : ($entry['access']['reason'] ?? 'not_reviewed');
                $entry['access'] = ExchangeMetadataBuilder::unknown($reason);
            }
            $entries[$id] = $entry;
        }

        return $entries;
    }

    public static function eligible(array $entry): bool
    {
        return ($entry['spot'] ?? false) === true && ($entry['fetchOHLCV'] ?? false) === true
            && (bool) array_intersect($entry['timeframes'] ?? [], CandleTimeframe::SUPPORTED)
            && in_array($entry['access']['state'] ?? null, ['public', 'authentication_required'], true);
    }

    /** Used before cache reads or any network call. Credentials are never returned. */
    public function assertUsable(Exchange $exchange, bool $spotOnly = true, ?User $user = null, ?string $symbol = null): array
    {
        $entry = $this->all()[$exchange->class] ?? null;
        if ($entry === null) {
            throw new MarketCatalogException('exchange_removed', 'This exchange is no longer available in the installed CCXT library.', 422);
        }
        if (($entry['access']['state'] ?? 'unknown') === 'unknown') {
            throw new MarketCatalogException('access_unknown',
                'This exchange is unavailable while its candle-data access is reviewed.', 422);
        }
        if ($spotOnly && ($entry['spot'] ?? false) !== true) {
            throw new MarketCatalogException('spot_unsupported', 'This exchange does not provide spot markets.', 422);
        }
        if (($entry['fetchOHLCV'] ?? false) !== true || ! array_intersect($entry['timeframes'] ?? [], CandleTimeframe::SUPPORTED)) {
            throw new MarketCatalogException('candles_unsupported', 'This exchange does not provide a supported candle period.', 422);
        }
        if ($entry['access']['state'] === 'authentication_required') {
            $configuration = app(ExchangeCredentials::class)->settings($exchange, $user ?? ($symbol === null ? auth()->user() : null), $symbol, rotate: false);
            foreach ($entry['required_credentials'] ?? [] as $key) {
                if (! is_string($configuration[$key] ?? null) || trim($configuration[$key]) === '') {
                    throw new MarketCatalogException('authentication_required',
                        'This exchange requires valid API credentials to load market data. Add read-only keys in Settings → Exchange keys, or ask a server owner to share keys.', 422);
                }
            }
        }

        return $entry;
    }
}
