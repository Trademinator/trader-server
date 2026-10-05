<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\CandleTraining;
use App\Domain\Intelligence\HumanTraining;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CandleTrainingController extends Controller
{
    public function store(Request $request, CandleTraining $training): RedirectResponse
    {
        $data = $request->validate(['dataset' => ['required', 'uuid', 'exists:research_datasets,dataset_id']]);
        $decision = $training->start($request->user(), $data['dataset']);

        return redirect()->route('human-training.candles.show', [
            'dataset' => $data['dataset'], 'decision_at_ms' => $decision,
        ]);
    }

    public function show(Request $request, string $dataset, CandleTraining $training, HumanTraining $snapshots): Response
    {
        $data = $request->validate(['decision_at_ms' => ['nullable', 'integer', 'min:1']]);
        $state = $training->review($request->user(), $dataset,
            isset($data['decision_at_ms']) ? (int) $data['decision_at_ms'] : null);

        return response()->view('markets.candle-training', ['state' => $state,
            'datasets' => $snapshots->datasets()])->header('Cache-Control', 'no-store, private');
    }

    public function history(Request $request, string $dataset, CandleTraining $training): JsonResponse
    {
        $data = $request->validate([
            'decision_at_ms' => ['required', 'integer', 'min:1'],
            'before_ms' => ['required_without:after_ms', 'prohibits:after_ms', 'integer', 'min:1'],
            'after_ms' => ['required_without:before_ms', 'prohibits:before_ms', 'integer', 'min:1'],
        ]);

        $page = isset($data['after_ms'])
            ? $training->nextHistory($request->user(), $dataset, (int) $data['decision_at_ms'], (int) $data['after_ms'])
            : $training->history($request->user(), $dataset, (int) $data['decision_at_ms'], (int) $data['before_ms']);

        return response()->json($page)->header('Cache-Control', 'no-store, private');
    }


    public function autoLabel(Request $request, string $dataset, CandleTraining $training): JsonResponse
    {
        $data = $request->validate(['include_existing' => ['sometimes', 'boolean']]);

        return response()->json($training->autoLabels(
            $request->user(),
            $dataset,
            (bool) ($data['include_existing'] ?? false),
        ))->header('Cache-Control', 'no-store, private');
    }

    public function submitLabels(Request $request, string $dataset, CandleTraining $training): JsonResponse
    {
        $data = $request->validate([
            'delete_all' => ['sometimes', 'boolean'],
            'changes' => ['present', 'array', 'max:'.max(1, (int) config('intelligence.max_rows'))],
            'changes.*.decision_at_ms' => ['required', 'integer', 'min:1'],
            'changes.*.action' => ['nullable', Rule::in(CandleTraining::ACTIONS)],
        ]);
        $result = $training->submitLabels(
            $request->user(),
            $dataset,
            $data['changes'],
            (bool) ($data['delete_all'] ?? false),
        );

        return response()->json([...$result,
            'message' => 'Candle Training labels submitted. The reviewed labels are now available to the next model build.',
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $dataset, CandleTraining $training): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['decision_at_ms' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(CandleTraining::ACTIONS)]]);
        $label = $training->save($request->user(), $dataset, (int) $data['decision_at_ms'], $data['action']);

        if ($request->expectsJson()) {
            return response()->json([
                'decision_at_ms' => (int) $data['decision_at_ms'],
                'action' => $label->action,
                'message' => 'Candle marked '.strtoupper($label->action).'.',
            ]);
        }

        return redirect()->route('human-training.candles.show', [
            'dataset' => $dataset, 'decision_at_ms' => $data['decision_at_ms'],
        ])->with('status', 'Candle marked '.strtoupper($label->action).'.');
    }

    public function destroy(Request $request, string $dataset, CandleTraining $training): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['decision_at_ms' => ['required', 'integer', 'min:1']]);
        $training->delete($request->user(), $dataset, (int) $data['decision_at_ms']);
        $message = 'Candle label removed. This candle is now unlabelled, not HOLD. Rebuild intelligence to remove the deleted label from any already-published model.';

        if ($request->expectsJson()) {
            return response()->json([
                'decision_at_ms' => (int) $data['decision_at_ms'],
                'deleted' => true,
                'message' => $message,
            ]);
        }

        return redirect()->route('human-training.candles.show', [
            'dataset' => $dataset, 'decision_at_ms' => $data['decision_at_ms'],
        ])->with('status', $message);
    }
}
