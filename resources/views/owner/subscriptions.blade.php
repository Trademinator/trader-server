<x-owner.layout title="Global market subscriptions">
    <section class="owner-panel"><h2>Active subscriptions by exchange</h2>
        <div class="owner-scroll"><table class="owner-table"><thead><tr><th>Exchange</th><th>Users</th><th>Shared markets</th><th>Subscriptions</th></tr></thead><tbody>
            @forelse($exchanges as $exchange)<tr><td>{{ $exchange->class }}</td><td>{{ $exchange->users }}</td><td>{{ $exchange->markets }}</td><td>{{ $exchange->subscriptions }}</td></tr>@empty<tr><td colspan="4">No active subscriptions.</td></tr>@endforelse
        </tbody></table></div>{{ $exchanges->withQueryString()->links() }}
    </section>
    <section class="owner-panel"><h2>All subscriptions</h2>
        <form class="owner-filters" method="GET" action="{{ route('owner.subscriptions') }}">
            <label>Exchange or symbol<input name="q" value="{{ request('q') }}" maxlength="120"></label>
            <label>User UUID<input name="user" value="{{ request('user') }}"></label>
            <label>State<select name="state"><option value="">All</option><option value="active" @selected(request('state') === 'active')>Active</option><option value="inactive" @selected(request('state') === 'inactive')>Inactive</option></select></label><button>Filter</button>
        </form>
        <p class="owner-muted">{{ number_format($items->total()) }} matching subscriptions</p>
        <div class="owner-scroll"><table class="owner-table"><thead><tr><th>Market</th><th>User</th><th>State</th><th>Collector / period</th><th>Last successful pull</th></tr></thead><tbody>
            @forelse($items as $item)<tr><td><a href="{{ route('owner.markets.show', $item->market) }}">{{ $item->market->exchange->class }} · {{ $item->market->symbol }}</a></td><td><a href="{{ route('owner.users.show', $item->user) }}">{{ $item->user->name }}</a><small>{{ $item->user->email }}</small></td><td>{{ $item->active ? 'Active' : 'Inactive' }}</td><td>{{ $item->market->feed?->status ?? 'No feed' }} / {{ $item->market->feed?->selected_period ?? 'Pending' }}</td><td>{{ $item->market->feed?->last_pulled_at ?? 'Never' }}</td></tr>@empty<tr><td colspan="5">No matching subscriptions.</td></tr>@endforelse
        </tbody></table></div>{{ $items->links() }}
    </section>
</x-owner.layout>
