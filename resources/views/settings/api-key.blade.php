<x-layouts.app :title="__('Client API Keys | Settings')">
<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Client API keys')" :subheading="__('Create separate revocable keys for Trademinator Clients. Secrets are stored hashed and shown only once.')">
        @if ($newSecret)
            <div class="guide-notice mb-6">
                <strong>{{ __('Copy this key now. It will not be shown again.') }}</strong>
                <pre class="dashboard-command mt-2"><code>{{ $newSecret }}</code></pre>
            </div>
        @endif

        <x-form method="post" action="{{ route('settings.api-key.store') }}" class="space-y-5">
            <x-input type="text" name="label" :label="__('Label')" required maxlength="80" autocomplete="off" placeholder="Desktop Client" />
            <x-input type="datetime-local" name="expires_at" :label="__('Optional expiry')" autocomplete="off" />
            <div class="flex items-center gap-4">
                <x-button>{{ __('Create key') }}</x-button>
                <x-action-message class="me-3" on="api-key-created">{{ __('Created.') }}</x-action-message>
            </div>
        </x-form>

        <div class="mt-8 overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr><th class="text-left p-2">{{ __('Label') }}</th><th class="text-left p-2">{{ __('Prefix') }}</th><th class="text-left p-2">{{ __('Created') }}</th><th class="text-left p-2">{{ __('Last used') }}</th><th class="text-left p-2">{{ __('Expiry') }}</th><th class="text-left p-2">{{ __('Status') }}</th><th class="p-2"></th></tr></thead>
                <tbody>
                @forelse ($keys as $key)
                    <tr class="border-t border-slate-200 dark:border-slate-700">
                        <td class="p-2">{{ $key->label }}</td>
                        <td class="p-2"><code>{{ $key->prefix }}…</code></td>
                        <td class="p-2">{{ $key->created_at?->utc()->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="p-2">{{ $key->last_used_at?->utc()->format('Y-m-d H:i') ?? 'Never' }}</td>
                        <td class="p-2">{{ $key->expires_at?->utc()->format('Y-m-d H:i') ?? 'No expiry' }}</td>
                        <td class="p-2">{{ $key->revoked_at ? 'Revoked' : ($key->expires_at?->isPast() ? 'Expired' : 'Active') }}</td>
                        <td class="p-2 text-right">
                            @if (!$key->revoked_at && !($key->expires_at?->isPast()))
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
