<x-owner.layout title="User management">
    <section class="owner-panel">
        <form class="owner-filters" method="GET" action="{{ route('owner.users') }}">
            <label>Search name, email or UUID<input name="q" value="{{ request('q') }}" maxlength="120"></label>
            <label>Account status<select name="status"><option value="">All accounts</option>@foreach(['active' => 'Active', 'suspended' => 'Suspended', 'unverified' => 'Unverified'] as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
            <button>Filter users</button>
        </form>
        <p class="owner-muted">{{ number_format($users->total()) }} matching users</p>
        <div class="owner-scroll"><table class="owner-table"><thead><tr><th>User</th><th>Account</th><th>Subscriptions</th><th>Last sign-in / activity</th><th>Joined</th></tr></thead><tbody>
            @forelse($users as $user)
                <tr><td><a href="{{ route('owner.users.show', $user) }}">{{ $user->name }}</a><small>{{ $user->email }}</small><small><code>{{ $user->user_id }}</code></small></td>
                    <td><span class="owner-badge">{{ $user->isOwner() ? 'Owner' : ($user->suspended_at ? 'Suspended' : 'Active') }}</span><small>{{ $user->email_verified_at ? 'Verified email' : 'Email unverified' }}</small></td>
                    <td>{{ $user->active_subscriptions_count }} active / {{ $user->subscriptions_count }} total</td>
                    <td>{{ $user->last_login_at ?? 'Not recorded' }}<small>{{ $user->last_seen_at ?? 'No recorded activity' }}</small></td><td>{{ $user->created_at }}</td></tr>
            @empty<tr><td colspan="5">No matching users.</td></tr>@endforelse
        </tbody></table></div>
        {{ $users->links() }}
    </section>
</x-owner.layout>
