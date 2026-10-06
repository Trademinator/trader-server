<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\HumanCandleKnn;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Research\DatasetStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class AuditHumanCandleTraining extends Command
{
    protected $signature = 'trademinator:human-candle-audit {exchange} {symbol} {period}
        {--dataset= : Current-version target dataset; defaults to the published model dataset}
        {--timeout=300 : Eligibility inspection budget in seconds, without KNN validation}';

    protected $description = 'Read-only audit of recorded human candles, current-feature reuse and exclusion reasons';

    public function handle(DatasetStore $datasets, HumanCandleKnn $knn, ModelStore $models): int
    {
        try {
            $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT);
            if ($timeout === false || $timeout < 1 || $timeout > 3600) {
                throw new InvalidArgumentException('--timeout must be an integer between 1 and 3600 seconds.');
            }
            $exchange = $this->argument('exchange');
            $symbol = $this->argument('symbol');
            $period = $this->argument('period');
            $dataset = $this->option('dataset');
            if ($dataset === null) {
                $id = DB::table('intelligence_heads')->where('market_key', ModelStore::marketKey($exchange, $symbol, $period))->value('model_id');
                if ($id === null) {
                    throw new InvalidArgumentException('No published model: specify a current target with --dataset.');
                }
                $dataset = $models->report($id)['dataset_id'];
            }
            $manifest = $datasets->manifest($dataset);
            if ([$manifest['exchange'], $manifest['symbol'], $manifest['period']] !== [$exchange, $symbol, $period]) {
                throw new InvalidArgumentException('Target dataset does not match the requested exchange, symbol and period.');
            }
            if ($manifest['as_of_ms'] > now()->getTimestampMs()) {
                throw new InvalidArgumentException('Target dataset cutoff cannot be in the future.');
            }
            $started = microtime(true);
            $result = $knn->audit($manifest, $started + $timeout);
            $this->line(json_encode(['read_only' => true, 'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
                'dataset_id' => $dataset, ...$result, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
