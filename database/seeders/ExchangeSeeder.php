<?php

namespace Database\Seeders;

use App\Domain\MarketData\MarketCatalog;
use App\Models\Exchange;
use ccxt;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ExchangeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $created = DB::transaction(function (): int {
            $count = 0;
            foreach (ccxt\Exchange::$exchanges as $id) {
                // Restore missing catalogue entries; retain existing IDs, names and settings.
                $exchange = Exchange::query()->firstOrCreate(['class' => $id], ['name' => $id, 'config' => '{}']);
                $count += $exchange->wasRecentlyCreated ? 1 : 0;
            }

            return $count;
        });
        // Invalidate after commit, including an old cached empty list on a no-op run.
        Cache::forget(MarketCatalog::EXCHANGES_CACHE);
        $this->command?->info("Added {$created} missing exchange entries. Existing exchange settings and all user data were preserved.");
    }
}
