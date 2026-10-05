<x-owner.layout title="History recovery">
    <section class="owner-panel">
        <h2>{{ $market->exchange->name }} · {{ $market->symbol }} · {{ $period ?? 'period pending' }}</h2>
        <p>Repair source history, replay affected features, create a fresh dataset and validate the model. A completed repair does not guarantee validation success.</p>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        <form method="POST" action="{{ route('owner.history-recovery.store', $market) }}">@csrf
            <label><input type="checkbox" name="retry_unavailable" value="1"> Retry paused or unavailable intervals after fixing access or restoring history.</label>
            <button type="submit">Repair history and rebuild</button>
        </form>
        <p class="owner-muted">Uses the existing history and intelligence queues. Repeated clicks are coalesced. The current model schema is preserved; a custom schema requires its original CLI workflow.</p>
    </section>
    <section class="owner-panel">
        <h2>Recovery stages</h2>
        <dl class="owner-details">
            <dt>Source candles</dt><dd>{{ array_sum($gapCounts) }} unresolved repair intervals. This is a tracked scan result, not proof of complete exchange history.</dd>
            <dt>Feature replay</dt><dd>{{ str_replace('_', ' ', $features) }}</dd>
            <dt>History revision</dt><dd>{{ $history->history_revision ?? 0 }} · Last trained revision {{ $history->trained_revision ?? 0 }}</dd>
            <dt>Dataset</dt><dd>{{ $dirty ? 'Replacement pending' : ($report['dataset_id'] ?? 'Not built') }}</dd>
            <dt>Snapshot revisions</dt><dd>{{ $reviewCount }} replacement snapshots have no submitted review or candle label. Historical revisions are retained.</dd>
            <dt>Last model validation</dt><dd>{{ $report['status'] ?? 'No model' }} · {{ $report['reason'] ?? 'Not evaluated' }}{{ $dirty ? ' (older revision; rebuild pending)' : '' }}</dd>
            <dt>Last scan request</dt><dd>{{ $request->status ?? 'Not requested' }}</dd>
        </dl>
        @if ($history?->build_error)<p role="alert">{{ $history->build_error }}</p>@endif
        @if ($request?->error)<p role="alert">{{ $request->error }}</p>@endif
        @if ($report)<p><a href="{{ route('owner.intelligence.show', $report['model_id']) }}">Open the full validation report</a></p>@endif
        <p class="owner-muted">Unreviewed human enhancements do not block the ordinary model. Gaps are never converted into flat candles or HOLD labels.</p>
    </section>
    <section class="owner-panel">
        <h2>Unresolved intervals (up to 100 most recent)</h2>
        @if ($market->exchange->class === 'kraken')
            <p>Kraken's OHLC endpoint exposes only its 720 most recent entries. An older hole may require verified same-exchange archived history; retrying cannot override provider retention.</p>
        @endif
        <div class="owner-scroll"><table class="owner-table">
            <thead><tr><th>From</th><th>Through</th><th>Status</th><th>Reason</th><th>Last error</th></tr></thead><tbody>
            @forelse ($gaps as $gap)
                <tr><td><x-display-time :value="(int) $gap->from_ms" unit="milliseconds" /></td>
                    <td><x-display-time :value="(int) $gap->to_ms" unit="milliseconds" /></td>
                    <td>{{ $gap->status }}</td><td>{{ $gap->reason }}</td><td>{{ $gap->last_error }}</td></tr>
            @empty<tr><td colspan="5">No unresolved intervals are currently tracked. Run recovery for a fresh scan.</td></tr>@endforelse
            </tbody></table></div>
    </section>
</x-owner.layout>
