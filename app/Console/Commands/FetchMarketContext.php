<?php

namespace App\Console\Commands;

use App\Domain\Features\CoinGeckoCollector;
use App\Models\CoinGeckoMarketMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class FetchMarketContext extends Command
{
    protected $signature = 'trademinator:fetch-market-context {--coin=} {--vs-currency=} {--exchange=} {--symbol=}';

    protected $description = 'Fetch fresh CoinGecko context immediately for one mapped coin/quote or exchange/pair';

    public function handle(CoinGeckoCollector $collector): int
    {
        $coin = trim((string) $this->option('coin'));
        $currency = strtolower(trim((string) $this->option('vs-currency')));
        $exchange = strtolower(trim((string) $this->option('exchange')));
        $symbol = strtoupper(trim((string) $this->option('symbol')));
        $coinSelection = $coin !== '' || $currency !== '';
        $marketSelection = $exchange !== '' || $symbol !== '';
        if ($coinSelection === $marketSelection
            || ($coinSelection && ($coin === '' || $currency === ''))
            || ($marketSelection && ($exchange === '' || $symbol === ''))) {
            $this->error('Use either --coin with --vs-currency, or --exchange with --symbol.');

            return self::FAILURE;
        }

        $query = CoinGeckoMarketMapping::query()->with('market')
            ->where('status', 'resolved')->whereNotNull('coin_id')->whereNotNull('vs_currency');
        if ($coinSelection) {
            $query->where('coin_id', $coin)->where('vs_currency', $currency);
        } else {
            $query->whereHas('market', fn ($market) => $market->where('symbol', $symbol)
                ->whereHas('exchange', fn ($query) => $query->where('class', $exchange)));
        }
        // A coin can be mapped on several exchanges. Prefer a known category
        // so its snapshot also includes the shared category observations.
        $mapping = $query->orderByDesc('category')->orderBy('coin_gecko_market_mapping_id')->first();
        if ($mapping === null) {
            $this->error('No resolved CoinGecko mapping matches that selection. Resolve the market mapping first.');

            return self::FAILURE;
        }

        $lock = Cache::lock('trademinator:coingecko-context', 3600);
        if (! $lock->get()) {
            $this->error('Context collection is already running. Retry after it finishes.');

            return self::FAILURE;
        }
        try {
            $this->info('Fetching '.$mapping->coin_id.' / '.strtoupper($mapping->vs_currency).' synchronously...');
            $count = $collector->fetchForMapping($mapping);
            if ($count === 0) {
                $this->error('CoinGecko returned no fresh data for this coin/quote. No snapshot was stored.');

                return self::FAILURE;
            }
            $this->info('Stored '.$count.' context snapshot(s). No jobs were queued.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
