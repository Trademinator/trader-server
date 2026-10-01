<x-owner.layout :title="$market->exchange->class.' · '.$market->symbol">
    <div class="owner-grid"><div class="owner-stat"><span>Selected candle period</span><strong>{{ $period ?? 'Pending' }}</strong></div><div class="owner-stat"><span>Stored candles · selected period</span><strong>{{ number_format($candles) }}</strong></div><div class="owner-stat"><span>Current-version feature rows</span><strong>{{ number_format($features) }}</strong></div></div>
    <section class="owner-panel"><h2>Collection and intelligence</h2><dl class="owner-details">
        <dt>Market UUID</dt><dd><code>{{ $market->market_id }}</code></dd><dt>Collector</dt><dd>{{ $market->feed?->status ?? 'No feed' }}</dd>
        <dt>Last successful pull</dt><dd><x-display-time :value="$market->feed?->last_pulled_at" fallback="Never" /></dd><dt>Next scheduled pull</dt><dd><x-display-time :value="$market->feed?->next_pull_at" fallback="Not scheduled" /></dd>
        <dt>Collector error</dt><dd>{{ $market->feed?->last_error ? 'An error was recorded. Inspect the syslog feed events for this market UUID.' : 'No current error recorded' }}</dd>
        <dt>Current model</dt><dd>@if($head)<a href="{{ route('owner.intelligence.show', $head) }}">{{ $head }}</a>@else Not trained @endif</dd>
        <dt>Historical backfill</dt><dd>{{ $backfill?->status ?? 'Not started' }} @if($backfill?->reason) · {{ $backfill->reason }} @endif</dd>
        <dt>Historical candles received</dt><dd>{{ number_format($backfill?->candles_received ?? 0) }}</dd>
        <dt>Backfill training revisions</dt><dd>{{ $backfill?->trained_revision ?? 0 }} trained / {{ $backfill?->history_revision ?? 0 }} collected</dd>
    </dl></section>
    <section class="owner-panel"><h2>Subscribers</h2><div class="owner-scroll"><table class="owner-table"><thead><tr><th>User</th><th>State</th><th>Subscribed at</th></tr></thead><tbody>
        @forelse($subscriptions as $item)<tr><td><a href="{{ route('owner.users.show', $item->user) }}">{{ $item->user->name }}</a><small>{{ $item->user->email }}</small></td><td>{{ $item->active ? 'Active' : 'Inactive' }}</td><td><x-display-time :value="$item->created_at" /></td></tr>@empty<tr><td colspan="3">No subscribers.</td></tr>@endforelse
    </tbody></table></div>{{ $subscriptions->links() }}</section>
</x-owner.layout>
