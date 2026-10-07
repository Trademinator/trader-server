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
            ['name' => 'Market features', 'version' => FeatureEngine::VERSION],
            ['name' => 'Automatic pattern ablation', 'version' => AutomaticPatternAblation::VERSION],
            ['name' => 'Automatic schema selection', 'version' => AutomaticSchemaSelection::VERSION],
            ['name' => 'Candle guidance', 'version' => CandleGuidance::VERSION],
            ['name' => 'Human candle KNN', 'version' => HumanCandleKnn::VERSION],
            ['name' => 'Human candle projection', 'version' => HumanCandleProjection::VERSION],
            ['name' => 'Human outcome guidance', 'version' => HumanGuidance::VERSION],
            ['name' => 'Human training snapshots', 'version' => HumanTraining::VERSION],
            ['name' => 'Intelligence trainer', 'version' => IntelligenceTrainer::VERSION],
            ['name' => 'KNN ensemble scoring', 'version' => KnnEnsemble::VERSION],
            ['name' => 'Lead/lag trainer', 'version' => LeadLagTrainer::VERSION],
            ['name' => 'Normalized vectors', 'version' => NormalizedVector::VERSION],
            ['name' => 'Pattern catalog', 'version' => PatternCatalog::VERSION],
            ['name' => 'Outcome semantic labels', 'version' => SemanticLabels::VERSION],
            ['name' => 'Legacy label definition', 'version' => LabelDefinition::VERSION],
            ['name' => 'Risk factor algorithm', 'version' => RiskFactorCalculator::VERSION],
            ['name' => 'Reconstructed candle provenance', 'version' => CandleProvenance::VERSION],
            ['name' => 'Research dataset format', 'version' => 'm3-dataset-v1'],
            ['name' => 'Human training export', 'version' => HumanTrainingExport::FORMAT],
            ['name' => 'Portable JSON format', 'version' => PortableJson::FORMAT],
            ['name' => 'Intelligence artifact format', 'version' => 'm4-intelligence-v2'],
            ['name' => 'Archive format / ticker schema', 'version' => config('archive.format_version').' / '.config('archive.ticker_schema_version')],
            ['name' => 'Feature checkpoint format', 'version' => (string) config('archive.feature_checkpoint_version')],
            ['name' => 'Portable archive format', 'version' => (string) config('archive.portable_format_version')],
        ];

        $stored = collect();
        foreach (DB::table('market_features')->selectRaw('version, COUNT(*) total, MAX(created_at) latest')->groupBy('version')->get() as $row) {
            $stored->push($this->row('Market features', $row->version, $row->total, $row->latest,
                $row->version === FeatureEngine::VERSION, $row->version === FeatureEngine::VERSION ? 0 : $row->total, 'features'));
        }
        foreach (DB::table('human_training_snapshots as snapshots')
            ->leftJoin('human_training_reviews as reviews', 'reviews.snapshot_id', '=', 'snapshots.snapshot_id')
            ->leftJoin('human_candle_labels as labels', 'labels.snapshot_id', '=', 'snapshots.snapshot_id')
            ->selectRaw('snapshots.version, COUNT(DISTINCT snapshots.snapshot_id) total, MAX(snapshots.created_at) latest,
                COUNT(DISTINCT CASE WHEN reviews.review_id IS NULL AND labels.candle_label_id IS NULL THEN snapshots.snapshot_id END) unreferenced')
            ->groupBy('snapshots.version')->get() as $row) {
            $purgeable = $row->version === HumanTraining::VERSION ? 0 : (int) $row->unreferenced;
            $stored->push($this->row('Human training snapshots', $row->version, $row->total, $row->latest,
                $row->version === HumanTraining::VERSION, $purgeable, 'human-snapshots'));
        }
        foreach (DB::table('intelligence_models as models')->leftJoin('intelligence_heads as heads', 'heads.model_id', '=', 'models.model_id')
            ->select('models.report', 'models.created_at', 'heads.model_id as current_id')->cursor() as $model) {
            $report = json_decode($model->report, true);
            $version = (string) ($report['validation_version'] ?? 'unknown');
            $key = 'Intelligence models|'.$version;
            $existing = $stored->firstWhere('key', $key);
            if ($existing) {
                $existing->total++;
                $existing->latest = max($existing->latest, $model->created_at);
                $existing->purgeable += $model->current_id ? 0 : 1;
            } else {
                $stored->push($this->row('Intelligence models', $version, 1, $model->created_at,
                    $version === IntelligenceTrainer::VERSION, $model->current_id ? 0 : 1, 'models'));
            }
        }
        foreach (DB::table('feature_checkpoints')->selectRaw('feature_version, checkpoint_version, COUNT(*) total, MAX(created_at) latest')
            ->groupBy('feature_version', 'checkpoint_version')->get() as $row) {
            $version = $row->feature_version.' / '.$row->checkpoint_version;
            $current = $version === FeatureEngine::VERSION.' / '.config('archive.feature_checkpoint_version');
            $stored->push($this->row('Feature checkpoints', $version, $row->total, $row->latest, $current, $current ? 0 : $row->total, 'checkpoints'));
        }
        foreach (DB::table('archive_catalog')->selectRaw('format_version, schema_version, COUNT(*) total, MAX(created_at) latest')
            ->groupBy('format_version', 'schema_version')->get() as $row) {
            $version = $row->format_version.' / '.$row->schema_version;
            $current = $version === config('archive.format_version').' / '.config('archive.ticker_schema_version');
            $stored->push($this->row('Archive catalog', $version, $row->total, $row->latest, $current, 0, null));
        }

        $datasets = [];
        foreach (DB::table('research_datasets')->orderByDesc('created_at')->cursor() as $dataset) {
            $manifest = json_decode($dataset->manifest, true);
            $version = implode(' · ', array_filter([
                $manifest['format_version'] ?? 'unknown format',
                $manifest['feature_version'] ?? null,
                $manifest['label_definition']['version'] ?? null,
            ]));
            $current = ($manifest['format_version'] ?? null) === 'm3-dataset-v1'
                && ($manifest['feature_version'] ?? null) === FeatureEngine::VERSION
                && ($manifest['label_definition']['version'] ?? null) === SemanticLabels::VERSION;
            $datasets[$version] ??= (object) ['total' => 0, 'latest' => null, 'current' => $current, 'purgeable' => 0];
            $datasets[$version]->total++;
            $datasets[$version]->latest = max($datasets[$version]->latest ?? '', $dataset->created_at);
            $referenced = DB::table('intelligence_models')->where('dataset_id', $dataset->dataset_id)->exists()
                || DB::table('research_backtests')->where('dataset_id', $dataset->dataset_id)->exists()
                || DB::table('human_training_snapshots')->where('dataset_id', $dataset->dataset_id)->exists();
            $datasets[$version]->purgeable += $referenced ? 0 : 1;
        }
        foreach ($datasets as $version => $row) {
            $stored->push($this->row('Research datasets', $version, $row->total, $row->latest, $row->current, $row->purgeable, 'datasets'));
        }

        return view('owner.status', ['definitions' => $definitions, 'stored' => $stored->sortBy([
            ['family', 'asc'], ['current', 'desc'], ['latest', 'desc'],
        ])->values()]);
    }

    public function purge(Request $request): RedirectResponse
    {
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
        $ids = DB::table('intelligence_models as models')->leftJoin('intelligence_heads as heads', 'heads.model_id', '=', 'models.model_id')
            ->whereNull('heads.model_id')->pluck('models.model_id');
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
        $ids = DB::table('research_datasets as datasets')
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

    private function row(string $family, string $version, int $total, mixed $latest, bool $current, int $purgeable, ?string $scope): object
    {
        return (object) (compact('family', 'version', 'total', 'latest', 'current', 'purgeable', 'scope') + ['key' => $family.'|'.$version]);
    }
}
