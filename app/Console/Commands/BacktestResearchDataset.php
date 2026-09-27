<?php

namespace App\Console\Commands;

use App\Domain\Research\BaselineBacktester;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\ResearchInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class BacktestResearchDataset extends Command
{
    protected $signature = 'trademinator:backtest {dataset} {--strategy=majority} {--train=500} {--test=100} {--gap=0} {--rolling}';

    protected $description = 'Run purged walk-forward baseline evaluation on a frozen M3 dataset';

    public function handle(DatasetStore $store, BaselineBacktester $backtester): int
    {
        $path = $temporary = null;
        try {
            $train = ResearchInput::integer($this->option('train'), 'Train size');
            $test = ResearchInput::integer($this->option('test'), 'Test size');
            $gap = ResearchInput::integer($this->option('gap'), 'Gap', 0);
            [$manifest, $rows] = $store->load($this->argument('dataset'));
            $report = $backtester->run($manifest, $rows, $this->option('strategy'), $train, $test, $gap, ! $this->option('rolling'));
            $report['backtest_id'] = (string) Str::uuid7();
            $report['created_at'] = now()->toIso8601String();
            $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
            $path = $store->directory($manifest['dataset_id']).'/backtest-'.$report['backtest_id'].'.json';
            $temporary = $path.'.tmp';
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || ! rename($temporary, $path)) {
                throw new RuntimeException('Could not save backtest report.');
            }
            DB::table('research_backtests')->insert(['backtest_id' => $report['backtest_id'], 'dataset_id' => $manifest['dataset_id'],
                'report' => json_encode($report, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $this->line(json_encode(['backtest_id' => $report['backtest_id'], 'dataset_id' => $manifest['dataset_id'],
                'report' => $path, 'folds' => count($report['folds']), 'classification' => $report['classification'],
                'net_return' => $report['portfolio']['net_return'], 'trades' => $report['portfolio']['trades'],
                'benchmark_net_return' => $report['always_buy_benchmark']['net_return']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            foreach ([$temporary, $path] as $file) {
                if ($file !== null && is_file($file)) {
                    unlink($file);
                }
            }
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
