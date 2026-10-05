<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\ValidationGateCalibration;
use App\Domain\Research\DatasetStore;
use App\Helpers\Decimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class AnalyzeValidationGates extends Command
{
    protected $signature = 'trademinator:analyze-validation-gates
        {--directional=5,20,30,50,75,100 : Comma-separated candidate minimum directional counts}
        {--precision=0.55,0.60,0.65 : Comma-separated candidate absolute semantic precision floors}
        {--baseline-lift=0.10 : Required Wilson lower-bound lift over the training prediction-mix baseline}
        {--wilson-floor=0.50 : Absolute Wilson 95% lower-bound floor}
        {--all-models : Include historical models instead of current heads only}
        {--limit=500 : Maximum models when --all-models is used}
        {--json : Print the complete report as JSON}';

    protected $description = 'Analyze Server model holdouts to calibrate directional-count and semantic-precision readiness gates';

    public function handle(
        ModelStore $models,
        DatasetStore $datasets,
        ValidationGateCalibration $calibration
    ): int {
        try {
            $directional = $this->positiveIntegers((string) $this->option('directional'));
            $precision = $this->unitFloats((string) $this->option('precision'));
            $baselineLift = $this->unitFloat($this->option('baseline-lift'), 'baseline-lift');
            $wilsonFloor = $this->unitFloat($this->option('wilson-floor'), 'wilson-floor');
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 5000) {
                throw new InvalidArgumentException('--limit must be an integer from 1 to 5000.');
            }
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $query = DB::table('intelligence_models as models')
            ->select(['models.model_id', 'models.created_at'])
            ->orderByDesc('models.created_at');
        if (! $this->option('all-models')) {
            $query->join('intelligence_heads as heads', 'heads.model_id', '=', 'models.model_id');
        } else {
            $query->limit($limit);
        }

        $analyses = [];
        $skipped = [];
        foreach ($query->get() as $record) {
            try {
                $artifact = $models->load((string) $record->model_id);
                [, $rows] = $datasets->load((string) $artifact['dataset_id']);
                $analyses[] = $calibration->analyze($artifact, $rows);
            } catch (Throwable $error) {
                $skipped[] = [
                    'model_id' => (string) $record->model_id,
                    'reason' => $error->getMessage(),
                ];
            }
        }

        $distribution = array_map(
            fn (int $minimum): array => [
                'minimum_directional' => $minimum,
                'models' => count(array_filter(
                    $analyses,
                    fn (array $row): bool => $row['directional'] >= $minimum
                )),
            ],
            $directional
        );

        $candidates = [];
        foreach ($directional as $minimumDirectional) {
            foreach ($precision as $minimumPrecision) {
                $passed = count(array_filter(
                    $analyses,
                    fn (array $row): bool => $calibration->passes(
                        $row,
                        $minimumDirectional,
                        $minimumPrecision,
                        $baselineLift,
                        $wilsonFloor
                    )
                ));
                $candidates[] = [
                    'min_directional_predictions' => $minimumDirectional,
                    'min_semantic_precision' => $minimumPrecision,
                    'baseline_lift' => $baselineLift,
                    'wilson_floor' => $wilsonFloor,
                    'passed_models' => $passed,
                    'analyzed_models' => count($analyses),
                    'pass_fraction' => $analyses === [] ? 0.0 : $passed / count($analyses),
                ];
            }
        }

        $report = [
            'generated_at' => now('UTC')->toIso8601String(),
            'scope' => $this->option('all-models') ? 'historical_models' : 'current_heads',
            'server_only' => true,
            'financial_execution_inputs_used' => false,
            'rule' => 'existing validation/coverage/contradiction gates plus Wilson95 >= max(wilson_floor, training_prediction_mix_baseline + baseline_lift)',
            'baseline_lift' => $baselineLift,
            'wilson_floor' => $wilsonFloor,
            'models' => $analyses,
            'skipped' => $skipped,
            'directional_distribution' => $distribution,
            'candidate_grid' => $candidates,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Analyzed %d Server model(s); skipped %d. No readiness state was changed.',
            count($analyses),
            count($skipped)
        ));
        $this->line(sprintf(
            'Candidate rule: Wilson95 >= max(%.1f%%, training prediction-mix baseline + %.1f%%), while preserving each model\'s existing validation-row, coverage and contradiction gates.',
            $wilsonFloor * 100,
            $baselineLift * 100
        ));

        if ($analyses !== []) {
            $this->table(
                ['Market', 'Status', 'Eval', 'Dir', 'Correct', 'Precision', 'Wilson95', 'Mix baseline', 'Coverage', 'Contradictions'],
                array_map(fn (array $row): array => [
                    $row['exchange'].' '.$row['symbol'].' '.$row['period'],
                    $row['status'],
                    $row['evaluated'],
                    $row['directional'],
                    $row['correct_directional'],
                    $this->percent($row['semantic_precision']),
                    $this->percent($row['wilson_95_lower']),
                    $this->percent($row['prediction_mix_baseline']),
                    $this->percent($row['coverage']),
                    $this->percent($row['contradiction_rate']),
                ], $analyses)
            );

            $this->newLine();
            $this->info('Directional sample distribution');
            $this->table(
                ['Minimum directional', 'Models meeting sample', 'Analyzed'],
                array_map(fn (array $row): array => [
                    $row['minimum_directional'],
                    $row['models'],
                    count($analyses),
                ], $distribution)
            );

            $this->newLine();
            $this->info('Candidate readiness grid');
            $this->table(
                ['Min directional', 'Min precision', 'Pass', 'Analyzed', 'Pass rate'],
                array_map(fn (array $row): array => [
                    $row['min_directional_predictions'],
                    $this->percent($row['min_semantic_precision']),
                    $row['passed_models'],
                    $row['analyzed_models'],
                    $this->percent($row['pass_fraction']),
                ], $candidates)
            );
        }

        if ($skipped !== []) {
            $this->newLine();
            $this->warn('Skipped models');
            $this->table(
                ['Model', 'Reason'],
                array_map(fn (array $row): array => [$row['model_id'], $row['reason']], $skipped)
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function positiveIntegers(string $raw): array
    {
        $values = [];
        foreach ($this->items($raw) as $value) {
            if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 100000) {
                throw new InvalidArgumentException('--directional values must be integers from 1 to 100000.');
            }
            $values[] = (int) $value;
        }

        return array_values(array_unique($values));
    }

    /**
     * @return list<float>
     */
    private function unitFloats(string $raw): array
    {
        $values = [];
        foreach ($this->items($raw) as $value) {
            $values[] = $this->unitFloat($value, 'precision');
        }

        return array_values(array_unique($values, SORT_REGULAR));
    }

    private function unitFloat(mixed $value, string $name): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)
            || (float) $value < 0 || (float) $value > 1) {
            throw new InvalidArgumentException('--'.$name.' must be a number from 0 to 1.');
        }

        return (float) $value;
    }

    /**
     * @return list<string>
     */
    private function items(string $raw): array
    {
        $items = preg_split('/\s*,\s*/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException('Candidate lists must not be empty.');
        }

        return $items;
    }

    private function percent(float $value): string
    {
        return Decimal::format($value * 100, 1).'%';
    }
}
