<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\OutcomeAudit;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\ResearchInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class AuditOutcomeIntelligence extends Command
{
    protected $signature = 'trademinator:outcome-audit {exchange} {symbol} {period}
        {--dataset= : Current semantic dataset; defaults to the published model dataset}
        {--k= : Fixed K for all research comparisons; defaults to the published Outcome K}
        {--timeout=3600 : Read-only research budget in seconds}';

    protected $description = 'Read-only Outcome KNN research across horizons and feature groups with final holdout reserved';

    public function handle(DatasetStore $datasets, ModelStore $models, OutcomeAudit $audit): int
    {
        try {
            $exchange = $this->argument('exchange');
            $symbol = $this->argument('symbol');
            $period = $this->argument('period');
            $timeout = ResearchInput::integer($this->option('timeout'), 'Timeout', 1, 14400);

            $modelId = DB::table('intelligence_heads')
                ->where('market_key', ModelStore::marketKey($exchange, $symbol, $period))
                ->value('model_id');
            $report = $modelId === null ? null : $models->report($modelId);
            $dataset = $this->option('dataset') ?? ($report['dataset_id'] ?? null);
            if ($dataset === null) {
                throw new InvalidArgumentException('No published model: specify a current semantic dataset with --dataset.');
            }

            $manifest = $datasets->manifest($dataset);
            if ([$manifest['exchange'], $manifest['symbol'], $manifest['period']] !== [$exchange, $symbol, $period]) {
                throw new InvalidArgumentException('Audit dataset does not match the requested exchange, symbol and period.');
            }

            $optionK = $this->option('k');
            $k = $optionK === null
                ? ($report['outcome']['algorithmic']['k'] ?? null)
                : ResearchInput::integer($optionK, 'K', 1, (int) config('intelligence.knn.k_cap'));
            if (! is_int($k)) {
                throw new InvalidArgumentException('No published Outcome K is available; specify --k.');
            }

            $started = microtime(true);
            $result = $audit->run($dataset, $k, $started + $timeout);
            $result['published_model_id'] = $modelId;
            $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
