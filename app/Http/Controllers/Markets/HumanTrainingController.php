<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\CandleTraining;
use App\Domain\Intelligence\HumanTraining;
use App\Domain\Intelligence\HumanTrainingExport;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\SubscribedPairOptions;
use App\Http\Controllers\Controller;
use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingReview;
use App\Models\HumanTrainingSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HumanTrainingController extends Controller
{
    public function index(Request $request, HumanTraining $training, CandleTraining $candleTraining, SubscribedPairOptions $pairOptions): Response
    {
        $selection = $request->validate([
            'exchange' => ['nullable', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:100'],
            'period' => ['nullable', Rule::in(CandleTimeframe::SUPPORTED)],
        ]);
        $reviews = HumanTrainingReview::query()->where('trainer_id', $request->user()->user_id);
        $pending = (clone $reviews)->whereNull('submitted_at')->where('expires_at', '>', now()->format('Y-m-d H:i:s.v'))->first();
        $datasets = $pairOptions->datasets($request->user(), $training->datasets(), allSubscribed: true);
        $selectedDataset = null;

        if (isset($selection['exchange'], $selection['symbol'], $selection['period'])) {
            foreach ($datasets as $dataset) {
                if (($dataset['exchange'] ?? null) === $selection['exchange']
                    && ($dataset['symbol'] ?? null) === $selection['symbol']
                    && ($dataset['period'] ?? null) === $selection['period']) {
                    $selectedDataset = $dataset['dataset_id'];
                    break;
                }
            }
        }

        return response()->view('markets.human-training', ['datasets' => $datasets, 'selectedDataset' => $selectedDataset,
            'pending' => $pending, 'completed' => (clone $reviews)->whereIn('label', HumanTraining::LABELS)->count(),
            'candleCompleted' => $candleTraining->count($request->user()),
            'statistics' => Gate::allows('manage-server') ? $this->statistics() : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, HumanTraining $training): RedirectResponse
    {
        $data = $request->validate(['dataset' => ['required', 'uuid', 'exists:research_datasets,dataset_id']]);
        $review = $training->assign($request->user(), $data['dataset']);

        return redirect()->route('human-training.show', $review->review_id);
    }

    public function show(Request $request, string $review, HumanTraining $training): Response
    {
        $item = HumanTrainingReview::query()->with('snapshot')->where('trainer_id', $request->user()->user_id)->findOrFail($review);

        return response()->view('markets.human-training-review', ['review' => $item, 'snapshot' => $training->display($item),
            'labels' => HumanTraining::LABELS])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $review, HumanTraining $training): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', Rule::in([...HumanTraining::LABELS, 'skip'])],
            'confidence' => ['nullable', 'integer', 'between:0,100'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $training->submit($request->user(), $review, $data['label'], isset($data['confidence']) ? (int) $data['confidence'] : null, $data['reason'] ?? null);

        return redirect()->route('human-training.show', $review)->with('status', 'Trend assessment saved. It will be considered at the next model build.');
    }

    public function export(HumanTrainingExport $export): BinaryFileResponse
    {
        Gate::authorize('manage-server');
        $directory = storage_path('app/private/exports');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/human-training-'.Str::uuid7().'.jsonl';
        $export->write($path);

        return response()->download($path, basename($path), ['Cache-Control' => 'no-store, private'])->deleteFileAfterSend(true);
    }

    private function statistics(): array
    {
        $snapshots = HumanTrainingSnapshot::query()->whereHas('reviews', fn ($query) => $query->whereIn('label', HumanTraining::LABELS))
            ->with(['reviews' => fn ($query) => $query->whereIn('label', HumanTraining::LABELS)])
            ->orderByDesc('decision_at_ms')->limit(1000)->get();
        $trainers = [];
        $shared = $disputed = 0;
        foreach ($snapshots as $snapshot) {
            $reviews = $snapshot->reviews;
            $votes = $reviews->countBy('label');
            $shared += (int) ($reviews->count() > 1);
            $disputed += (int) ($reviews->pluck('label')->unique()->count() > 1);
            foreach ($reviews as $review) {
                $id = $review->trainer_id;
                $trainers[$id] ??= ['labels' => 0, 'peer_comparisons' => 0, 'peer_agreements' => 0];
                $trainers[$id]['labels']++;
                $trainers[$id]['peer_comparisons'] += $reviews->count() - 1;
                $trainers[$id]['peer_agreements'] += $votes[$review->label] - 1;
            }
        }

        return ['snapshots' => $snapshots->count(), 'shared' => $shared, 'disputed' => $disputed,
            'candle_labels' => HumanCandleLabel::query()->count(), 'trainers' => $trainers];
    }
}
