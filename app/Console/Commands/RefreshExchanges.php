<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\ExchangeTimezone;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\ExchangeMetadataBuilder;
use App\Domain\MarketData\MarketCatalog;
use App\Models\Exchange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RefreshExchanges extends Command
{
    protected $signature = 'trademinator:refresh-exchanges {--check : Inspect without changing metadata or exchange rows} {--json : Print the complete inspection report as JSON}';

    protected $description = 'Refresh CCXT access classifications and add missing exchange entries without deleting data';

    public function handle(ExchangeMetadataBuilder $builder, ExchangeMetadata $registry, ExchangeTimezone $timezones): int
    {
        $lock = fopen(storage_path('framework/ccxt-metadata.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Another local exchange refresh is running. Retry when it finishes.');

            return self::FAILURE;
        }
        try {
            $path = $registry->runtimePath();
            $previous = ExchangeMetadataBuilder::read(is_file($path) ? $path : resource_path('data/ccxt-exchanges.json'));
            $metadata = $builder->build(base_path());
            $report = ExchangeMetadataBuilder::report($metadata, $previous);
            $report['rows_added'] = 0;
            if (! $this->option('check')) {
                // Coordinate database inserts between nodes; each node publishes
                // its own metadata file under the separate local file lock.
                $report['rows_added'] = Cache::lock('trademinator:exchange-registry-sync', 120)->block(10, function () use ($metadata, $timezones): int {
                    return DB::transaction(function () use ($metadata, $timezones): int {
                        $added = 0;
                        foreach ($metadata['exchanges'] as $id => $entry) {
                            $exchange = Exchange::query()->firstOrCreate(['class' => $id], ['name' => $entry['name'], 'config' => '{}']);
                            $added += (int) $exchange->wasRecentlyCreated;
                            $timezones->seed($exchange, $entry);
                        }

                        return $added;
                    });
                });
                ExchangeMetadataBuilder::write($path, $metadata);
                Cache::forget(MarketCatalog::EXCHANGES_CACHE);
            }
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->info('CCXT '.$report['ccxt_version'].': '.count($report['exchanges']).' adapters inspected.');
                $this->table(['Classification', 'Count'], collect($report['counts'])->map(fn ($count, $state) => [$state, $count])->values()->all());
                foreach (['added', 'removed', 'changed', 'needs_review'] as $category) {
                    $this->line($category.': '.(implode(', ', $report[$category]) ?: 'none'));
                }
                $this->line($this->option('check') ? 'Check only; no metadata or database rows changed.'
                    : 'Added '.$report['rows_added'].' missing exchange rows. Existing settings and user data preserved.');
            }

            return self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
