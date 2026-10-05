<?php

namespace App\Http\Controllers\Owner;

use App\Domain\MarketData\HistoryRecovery;
use App\Http\Controllers\Controller;
use App\Models\Market;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class HistoryRecoveryController extends Controller
{
    public function show(Market $market, HistoryRecovery $recovery): View
    {
        Gate::authorize('manage-server');

        return view('owner.history-recovery', ['market' => $market, ...$recovery->describe($market)]);
    }

    public function store(Request $request, Market $market, HistoryRecovery $recovery): RedirectResponse
    {
        Gate::authorize('manage-server');
        $request->validate(['retry_unavailable' => ['sometimes', 'boolean']]);
        $id = $recovery->request($market, $request->boolean('retry_unavailable'));

        return to_route('owner.history-recovery.show', $market)
            ->with('status', 'Recovery request '.$id.' queued. The stages below update as the existing workers process it.');
    }
}
