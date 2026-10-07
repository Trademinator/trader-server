<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Archive\PortableJson;
use App\Domain\Client\RiskFactorCalculator;
use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\AutomaticPatternAblation;
use App\Domain\Intelligence\AutomaticSchemaSelection;
use App\Domain\Intelligence\CandleGuidance;
use App\Domain\Intelligence\HumanCandleKnn;
use App\Domain\Intelligence\HumanCandleProjection;
use App\Domain\Intelligence\HumanGuidance;
use App\Domain\Intelligence\HumanTraining;
use App\Domain\Intelligence\HumanTrainingExport;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\KnnEnsemble;
use App\Domain\Intelligence\LeadLagTrainer;
use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Intelligence\PatternCatalog;
use App\Domain\MarketData\CandleProvenance;
use App\Domain\Research\LabelDefinition;
use App\Domain\Research\SemanticLabels;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StatusController extends Controller
{
    public function index(): View
    {
        $definitions = [
            ['name' => 'Market features', 'version' => FeatureEngine::VERSION, 'scope' => 'features'],
            ['name' => 'Automatic pattern ablation', 'version' => AutomaticPatternAblation::VERSION],
            ['name' => 'Automatic schema selection', 'version' => AutomaticSchemaSelection::VERSION],
            ['name' => 'Candle guidance', 'version' => CandleGuidance::VERSION],
            ['name' => 'Human candle KNN', 'version' => HumanCandleKnn::VERSION],
            ['name' => 'Human candle projection', 'version' => HumanCandleProjection::VERSION],
            ['name' => 'Human outcome guidance', 'version' => HumanGuidance::VERSION],
            ['name' => 'Human training snapshots', 'version' => HumanTraining::VERSION, 'scope' => 'human-snapshots'],
            ['name' => 'Intelligence models', 'version' => IntelligenceTrainer::VERSION, 'scope' => 'models'],
            ['name' => 'KNN ensemble scoring', 'version' => KnnEnsemble::VERSION],
            ['name' => 'Lead/lag trainer', 'version' => LeadLagTrainer::VERSION],
            ['name' => 'Normalized vectors', 'version' => NormalizedVector::VERSION],
            ['name' => 'Pattern catalog', 'version' => PatternCatalog::VERSION],
            ['name' => 'Outcome semantic labels', 'version' => SemanticLabels::VERSION],
            ['name' => 'Legacy label definition', 'version' => LabelDefinition::VERSION],
            ['name' => 'Risk factor algorithm', 'version' => RiskFactorCalculator::VERSION],
            ['name' => 'Reconstructed candle provenance', 'version' => CandleProvenance::VERSION],
            ['name' => 'Research datasets', 'version' => 'm3-dataset-v1 · '.FeatureEngine::VERSION.' · '.SemanticLabels::VERSION, 'scope' => 'datasets'],
            ['name' => 'Human training export', 'version' => HumanTrainingExport::FORMAT],
            ['name' => 'Portable JSON format', 'version' => PortableJson::FORMAT],
            ['name' => 'Intelligence artifact format', 'version' => 'm4-intelligence-v2'],
            ['name' => 'Archive format / ticker schema', 'version' => config('archive.format_version').' / '.config('archive.ticker_schema_version')],
            ['name' => 'Feature checkpoints', 'version' => FeatureEngine::VERSION.' / '.config('archive.feature_checkpoint_version'), 'scope' => 'checkpoints'],
            ['name' => 'Portable archive format', 'version' => (string) config('archive.portable_format_version')],
        ];

        return view('owner.status', compact('definitions'));
    }

    public function purge(Request $request): RedirectResponse
    {
        set_time_limit(300);

        $data = $request->validate(['scope' => ['required', Rule::in(['features', 'human-snapshots', 'models', 'datasets', 'checkpoints'])]]);
        $deleted = match ($data['scope']) {
            'features' => DB::table('market_features')->where('version', '!=', FeatureEngine::VERSION)->delete(),
            'human-snapshots' => DB::table('human_training_snapshots')->where('version', '!=', HumanTraining::VERSION)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('human_training_reviews')->whereColumn('human_training_reviews.snapshot_id', 'human_training_snapshots.snapshot_id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('human_candle_labels')->whereColumn('human_candle_labels.snapshot_id', 'human_training_snapshots.snapshot_id'))->delete(),
            'models' => $this->purgeHistoricalModels(),
            'datasets' => $this->purgeUnreferencedDatasets(),
            'checkpoints' => DB::table('feature_checkpoints')->where(fn ($q) => $q
                ->where('feature_version', '!=', FeatureEngine::VERSION)
                ->orWhere('checkpoint_version', '!=', (int) config('archive.feature_checkpoint_version')))->delete(),
        };

        return back()->with('status', number_format($deleted).' obsolete '.$data['scope'].' record(s) purged.');
    }

    private function purgeHistoricalModels(): int
    {
        $ids = DB::table('intelligence_models as models')
            ->leftJoin('intelligence_heads as heads', 'heads.model_id', '=', 'models.model_id')
            ->whereNull('heads.model_id')
            ->where(function ($query): void {
                $query->whereNull('models.report->validation_version')
                    ->orWhere('models.report->validation_version', '!=', IntelligenceTrainer::VERSION);
            })
            ->pluck('models.model_id');
        $deleted = DB::table('intelligence_models')->whereIn('model_id', $ids)->delete();
        foreach ($ids as $id) {
            foreach ([rtrim(config('intelligence.path'), '/').'/'.$id.'.model', rtrim(config('intelligence.path'), '/').'/'.$id.'.knowledge.jsonl'] as $path) {
                File::delete($path);
            }
        }

        return $deleted;
    }

    private function purgeUnreferencedDatasets(): int
    {
        $ids = collect();
        foreach (DB::table('research_datasets')->select('dataset_id', 'manifest')->cursor() as $dataset) {
            $manifest = json_decode($dataset->manifest, true);
            $current = ($manifest['format_version'] ?? null) === 'm3-dataset-v1'
                && ($manifest['feature_version'] ?? null) === FeatureEngine::VERSION
                && ($manifest['label_definition']['version'] ?? null) === SemanticLabels::VERSION;
            if (! $current) {
                $ids->push($dataset->dataset_id);
            }
        }
        if ($ids->isEmpty()) {
            return 0;
        }

        $ids = DB::table('research_datasets as datasets')
            ->whereIn('datasets.dataset_id', $ids)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('intelligence_models')->whereColumn('intelligence_models.dataset_id', 'datasets.dataset_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('research_backtests')->whereColumn('research_backtests.dataset_id', 'datasets.dataset_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('human_training_snapshots')->whereColumn('human_training_snapshots.dataset_id', 'datasets.dataset_id'))
            ->pluck('datasets.dataset_id');
        $deleted = DB::table('research_datasets')->whereIn('dataset_id', $ids)->delete();
        foreach ($ids as $id) {
            File::deleteDirectory(rtrim(config('research.path'), '/').'/'.$id);
        }

        return $deleted;
    }
}
