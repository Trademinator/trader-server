<x-owner.layout :title="'User: '.$user->name">
    <section class="owner-panel">
        <dl class="owner-details"><dt>UUID</dt><dd><code>{{ $user->user_id }}</code></dd><dt>Status</dt><dd>{{ $user->isOwner() ? 'Owner' : ($user->suspended_at ? 'Suspended' : 'Active') }}</dd><dt>Email verification</dt><dd><x-display-time :value="$user->email_verified_at" fallback="Unverified" /></dd><dt>Last sign-in</dt><dd><x-display-time :value="$user->last_login_at" fallback="Not recorded" /></dd><dt>Last activity</dt><dd><x-display-time :value="$user->last_seen_at" fallback="Not recorded" /></dd></dl>
        @unless($user->isOwner())
            <h3>Edit profile</h3>
            <form class="owner-filters" method="POST" action="{{ route('owner.users.update', $user) }}">@csrf @method('PUT')
                <input type="hidden" name="operation" value="profile">
                <label>Name<input name="name" value="{{ old('name', $user->name) }}" required maxlength="255"></label>
                <label>Email<input name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="255"></label>
                <button>Save profile</button>
            </form>
            <p class="owner-muted">Changing an email address resets its verification.</p>
        @else
            <p style="margin-top:16px"><a href="{{ route('settings.profile.edit') }}">Edit your owner profile in Settings</a>.</p>
        @endunless
        <div class="owner-actions">
            @unless($user->isOwner())
                <form method="POST" action="{{ route('owner.users.update', $user) }}" onsubmit="return confirm('Apply this account status change? Suspending also stops subscriptions and revokes the API key.')">@csrf @method('PUT')
                    <input type="hidden" name="operation" value="{{ $user->suspended_at ? 'restore' : 'suspend' }}">
                    <button class="{{ $user->suspended_at ? '' : 'owner-danger' }}">{{ $user->suspended_at ? 'Restore account' : 'Suspend account' }}</button>
                </form>
            @endunless
            <form method="POST" action="{{ route('owner.users.update', $user) }}" onsubmit="return confirm('Revoke this user’s API key?')">@csrf @method('PUT')
                <input type="hidden" name="operation" value="revoke-api"><button class="owner-danger">Revoke API key</button>
            </form>
        </div>
        <p class="owner-muted" style="margin-top:14px">Restoring an account leaves subscriptions inactive until the user chooses them again.</p>
    </section>
    <section class="owner-panel"><h2>Market subscriptions</h2>
        <div class="owner-scroll"><table class="owner-table"><thead><tr><th>Market</th><th>Subscription</th><th>Collector</th><th>Period</th></tr></thead><tbody>
            @forelse($subscriptions as $item)<tr><td><a href="{{ route('owner.markets.show', $item->market) }}">{{ $item->market->exchange->class }} · {{ $item->market->symbol }}</a></td><td>{{ $item->active ? 'Active' : 'Inactive' }}</td><td>{{ $item->market->feed?->status ?? 'No feed' }}</td><td>{{ $item->market->feed?->selected_period ?? 'Pending' }}</td></tr>@empty<tr><td colspan="4">No subscriptions.</td></tr>@endforelse
        </tbody></table></div>{{ $subscriptions->links() }}
    </section>
</x-owner.layout>
