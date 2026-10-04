<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\MarketEventCandidate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MarketEventController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'decision' => ['nullable', Rule::in(['pending', 'yes', 'no', 'all'])],
        ]);
        $decision = $filters['decision'] ?? 'pending';

        $items = MarketEventCandidate::query()->with('reviewer:user_id,name,email')
            ->when($decision === 'pending', fn ($query) => $query->whereNull('owner_decision'))
            ->when(in_array($decision, ['yes', 'no'], true), fn ($query) => $query->where('owner_decision', $decision))
            ->orderByRaw('CASE WHEN owner_decision IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('source_seen_at')->orderByDesc('created_at')
            ->paginate(40)->withQueryString();

        $counts = [
            'pending' => MarketEventCandidate::query()->whereNull('owner_decision')->count(),
            'yes' => MarketEventCandidate::query()->where('owner_decision', 'yes')->count(),
            'no' => MarketEventCandidate::query()->where('owner_decision', 'no')->count(),
            'all' => MarketEventCandidate::query()->count(),
        ];

        return view('owner.events', compact('items', 'counts', 'decision'));
    }

    public function update(Request $request, MarketEventCandidate $candidate): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['yes', 'no'])]]);
        $candidate->forceFill([
            'owner_decision' => $data['decision'],
            'reviewed_by' => $request->user()->user_id,
            'reviewed_at' => now(),
        ])->save();

        return back()->with('status', $data['decision'] === 'yes'
            ? 'Event candidate confirmed.'
            : 'Event candidate rejected.');
    }
}
