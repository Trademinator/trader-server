<x-layouts.app :title="__('Client API Keys | Settings')">
<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :full-width="true" :heading="__('Client API keys')" :subheading="__('Create separate revocable keys for Trademinator Clients. Secrets are stored hashed and shown only once.')">
        @if ($newSecret)
            <div class="guide-notice mb-6">
                <strong>{{ __('Copy this key now. It will not be shown again.') }}</strong>
                <pre class="dashboard-command mt-2"><code>{{ $newSecret }}</code></pre>
            </div>
        @endif

        <x-form method="post" action="{{ route('settings.api-key.store') }}" class="max-w-lg space-y-5">
            <x-input type="text" name="label" :label="__('Label')" required maxlength="80" autocomplete="off" placeholder="Desktop Client" />
            <x-input type="datetime-local" name="expires_at" :label="__('Optional expiry')" autocomplete="off" data-time-input aria-describedby="expiry-timezone" />
            <input type="hidden" name="expires_timezone" value="{{ old('expires_timezone', auth()->user()->timezone ?? 'UTC') }}" data-input-timezone>
            <p id="expiry-timezone" class="text-sm">{{ __('Expiry time is in') }} <x-timezone-label />.</p>
            <div class="flex items-center gap-4">
                <x-button>{{ __('Create key') }}</x-button>
                <x-action-message class="me-3" on="api-key-created">{{ __('Created.') }}</x-action-message>
            </div>
        </x-form>

        @if (session('status') === 'api-keys-expired-deleted')
            <p class="mt-6 text-sm" role="status">
                {{ trans_choice('{0} No expired Client API keys to delete.|{1} Deleted :count expired Client API key.|[2,*] Deleted :count expired Client API keys.', (int) session('deleted_client_api_key_count', 0)) }}
            </p>
        @endif

        @php($expiryCutoff = now())
        @if ($keys->contains(fn ($key) => $key->expires_at !== null && $key->expires_at->lte($expiryCutoff)))
            <div class="mt-8 flex flex-wrap items-center gap-4">
                <x-form method="delete" action="{{ route('settings.api-key.expired.destroy') }}"
                    :onsubmit="'return window.confirm('.\Illuminate\Support\Js::from(__('Permanently delete all your expired Client API keys? Active keys will not be changed.')).')'">
                    <x-button>{{ __('Delete expired keys') }}</x-button>
                </x-form>
                <p class="guide-help">{{ __('Permanently removes expired keys only. Active keys are kept.') }}</p>
            </div>
        @endif

        <div class="mt-8 w-full overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr><th class="text-left p-2">{{ __('Label') }}</th><th class="text-left p-2">{{ __('Prefix') }}</th><th class="text-left p-2">{{ __('Created') }}</th><th class="text-left p-2">{{ __('Last used') }}</th><th class="text-left p-2">{{ __('Expiry') }}</th><th class="text-left p-2">{{ __('Status') }}</th><th class="p-2"></th></tr></thead>
                <tbody>
                @forelse ($keys as $key)
                    @php($expired = $key->expires_at !== null && $key->expires_at->lte($expiryCutoff))
                    <tr class="border-t border-slate-200 dark:border-slate-700">
                        <td class="p-2">{{ $key->label }}</td>
                        <td class="p-2"><code>{{ $key->prefix }}…</code></td>
                        <td class="p-2"><x-display-time :value="$key->created_at" precision="minutes" fallback="—" /></td>
                        <td class="p-2"><x-display-time :value="$key->last_used_at" precision="minutes" fallback="Never" /></td>
                        <td class="p-2"><x-display-time :value="$key->expires_at" precision="minutes" fallback="No expiry" /></td>
                        <td class="p-2">{{ $key->revoked_at ? 'Revoked' : ($expired ? 'Expired' : 'Active') }}</td>
                        <td class="p-2 text-right">
                            @if ($expired)
                                <x-form method="delete" action="{{ route('settings.api-key.expired.destroy', $key->getKey()) }}"
                                    :onsubmit="'return window.confirm('.\Illuminate\Support\Js::from(__('Permanently delete this expired Client API key?')).')'">
                                    <x-button :aria-label="__('Delete expired key: :label', ['label' => $key->label])">{{ __('Delete') }}</x-button>
                                </x-form>
                            @elseif (!$key->revoked_at)
                                <x-form method="delete" action="{{ route('settings.api-key.destroy', $key->getKey()) }}">
                                    <x-button>{{ __('Revoke') }}</x-button>
                                </x-form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-3">{{ __('No Client API keys yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="guide-help mt-4">{{ __('Use the secret as an Authorization: Bearer token with /api/v1/client endpoints. Revocation takes effect on the next request.') }}</p>
    </x-settings.layout>
</section>
</x-layouts.app>
