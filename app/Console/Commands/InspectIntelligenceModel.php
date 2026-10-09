<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\ModelStore;
use Illuminate\Console\Command;
use Throwable;

final class InspectIntelligenceModel extends Command
{
    protected $signature = 'trademinator:model-info {model}
        {--validation-summary : Show compact persisted Outcome/Action validation without reading private KNN sidecars}';

    protected $description = 'Verify an intelligence artifact or inspect its persisted validation summary';

    public function handle(ModelStore $models): int
    {
        try {
            $id = (string) $this->argument('model');
            if ($this->option('validation-summary')) {
                // DB-only diagnostics: do not deserialize or stream large knowledge artifacts.
                $this->line(json_encode($this->summary($models->report($id)),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $models->verify($id);
            $this->line(json_encode($models->report($id), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function summary(array $report): array
    {
        $outcome = $report['outcome'] ?? [];
        $outcomeAutomatic = $outcome['algorithmic'] ?? [];
        $action = $report['action'] ?? [];
        $automatic = $action['algorithmic'] ?? [];
        $human = $action['human'] ?? [];

        return [
            'model_id' => $report['model_id'] ?? null,
            'exchange' => $report['exchange'] ?? null,
            'symbol' => $report['symbol'] ?? null,
            'period' => $report['period'] ?? null,
            'validation_version' => $report['validation_version'] ?? null,
            'overall' => ['status' => $report['status'] ?? null, 'reason' => $report['reason'] ?? null],
            'outcome' => [
                'status' => $outcome['status'] ?? null,
                'reason' => $outcome['reason'] ?? null,
                'algorithmic' => [
                    'status' => $outcomeAutomatic['status'] ?? null,
                    'reason' => $outcomeAutomatic['reason'] ?? null,
                    'k' => $outcomeAutomatic['k'] ?? null,
                    'holdout' => array_intersect_key($outcomeAutomatic['holdout'] ?? [], array_flip([
                        'eligible', 'evaluated', 'supported', 'abstained', 'macro_f1',
                        'coverage', 'baseline', 'gates', 'failed_gates', 'ordinal',
                    ])),
                ],
                'human_status' => $outcome['human']['status'] ?? null,
            ],
            'action' => [
                'status' => $action['status'] ?? null,
                'reason' => $action['reason'] ?? null,
                'algorithmic' => [
                    'status' => $automatic['status'] ?? null,
                    'reason' => $automatic['reason'] ?? null,
                    'k' => $automatic['k'] ?? null,
                    'selection_reason' => $automatic['selection']['reason'] ?? null,
                    'holdout' => $this->actionHoldout($automatic['holdout'] ?? null),
                ],
                'human' => [
                    'status' => $human['status'] ?? null,
                    'samples' => $human['samples'] ?? null,
                    'class_counts' => $human['class_counts'] ?? null,
                    'holdout' => $this->actionHoldout($human['holdout'] ?? null),
                ],
            ],
        ];
    }

    private function actionHoldout(?array $holdout): ?array
    {
        if ($holdout === null) {
            return null;
        }

        return array_intersect_key($holdout, array_flip([
            'eligible', 'validation_status', 'evaluation_basis', 'evaluated', 'supported', 'abstained',
            'supported_holds', 'correct_holds', 'directional', 'correct', 'natural_class_counts',
            'directional_opportunities', 'semantic_precision', 'directional_annotation_agreement',
            'directional_wilson_95', 'required_directional_wilson_lower', 'prediction_mix_baseline',
            'coverage', 'coverage_gate_applied', 'contradiction_rate', 'opposite_annotation_rate',
            'classification_errors', 'by_action', 'gates', 'failed_gates',
        ]));
    }
}
