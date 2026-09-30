<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\CandleTraining;
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
        $state = $training->review($request->user(), $data['dataset']);

        return redirect()->route('human-training.candles.show', [
            'dataset' => $data['dataset'], 'decision_at_ms' => $state['payload']['decision_at_ms'],
        ]);
    }

    public function show(Request $request, string $dataset, CandleTraining $training): Response
    {
        $data = $request->validate(['decision_at_ms' => ['nullable', 'integer', 'min:1']]);
        $state = $training->review($request->user(), $dataset,
            isset($data['decision_at_ms']) ? (int) $data['decision_at_ms'] : null);

        return response()->view('markets.candle-training', ['state' => $state,
            'actions' => CandleTraining::ACTIONS])->header('Cache-Control', 'no-store, private');
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
