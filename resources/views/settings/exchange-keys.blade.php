<x-layouts.app :title="__('Exchange keys | Settings')">
    <section class="w-full">
        @include('partials.settings-heading')
        <x-settings.layout :heading="__('Exchange keys')" :subheading="__('Authenticate public market-data collection with your own exchange credentials.')">
            <div role="alert" class="rounded-lg border-2 border-red-600 bg-red-50 p-5 text-red-900 dark:border-red-500 dark:bg-red-950 dark:text-red-100">
                <h2 class="text-xl font-bold">{{ __('READ-ONLY KEYS ONLY') }}</h2>
                <p class="mt-2 font-semibold">{{ __('For security, restrict these keys to public market information wherever the exchange supports it. Never enable trading, transfers or withdrawals.') }}</p>
                <p class="mt-2 text-sm">{{ __('Use the minimum read permissions available. Do not enter a wallet private key or your account sign-in password. Trademinator cannot automatically verify the permissions you selected at the exchange.') }}</p>
            </div>
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">{{ __('Keys are encrypted and never displayed after saving. Your own keys take priority over shared owner keys. This is separate from the Trademinator Client API key.') }}</p>
            <details class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                <summary class="cursor-pointer font-medium">{{ __('How background collection and sharing work') }}</summary>
                <p class="mt-2">{{ __('A market has one shared public-data feed. The collector first uses credentials belonging to active subscribers of that market. If several subscribers provide personal keys, they rotate. Otherwise it rotates through keys explicitly shared by active server owners. Other users cannot view or manage your credentials.') }}</p>
                <p class="mt-2">{{ __('Rotation spreads collection work across keys. Exchange limits tied to an account or server IP still apply, and normal rate limiting stays enabled. Changes apply to new collection operations; a request already running may finish with its current key.') }}</p>
            </details>
            @if (session('status') === 'exchange-credentials-saved')
                <p role="status" class="mt-4 text-green-700 dark:text-green-400">{{ __('Exchange credentials saved.') }}</p>
            @elseif (session('status') === 'exchange-credentials-deleted')
                <p role="status" class="mt-4 text-green-700 dark:text-green-400">{{ __('Your exchange credentials were removed.') }}</p>
            @endif

            @if ($saved->isNotEmpty())
                <section class="mt-6 space-y-3" aria-label="{{ __('Saved credentials') }}">
                    <x-heading>{{ __('Your saved keys') }}</x-heading>
                    @foreach ($saved as $item)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <div>
                                <a class="font-medium underline" href="{{ route('settings.exchange-keys.index', ['exchange' => $item->exchange_id]) }}">{{ $item->exchange->name }} ({{ $item->exchange->class }})</a>
                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $item->is_shared && $isOwner ? __('Shared by you for server collection') : __('Personal keys saved') }}</p>
                            </div>
                            <x-form method="delete" action="{{ route('settings.exchange-keys.destroy', $item->exchange_id) }}">
                                <x-button variant="danger">{{ __('Remove') }}</x-button>
                            </x-form>
                        </div>
                    @endforeach
                </section>
            @endif

            <form method="get" action="{{ route('settings.exchange-keys.index') }}" class="mt-6 space-y-3">
                <label for="exchange" class="block font-medium">{{ __('Exchange') }}</label>
                <select id="exchange" name="exchange" required class="w-full rounded-lg border border-gray-300 bg-white p-2 text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">{{ __('Choose an exchange') }}</option>
                    @foreach ($exchanges as $exchange)
                        <option value="{{ $exchange->exchange_id }}" @selected($selected?->exchange_id === $exchange->exchange_id)>{{ $exchange->name }} ({{ $exchange->class }}){{ isset($sharedCounts[$exchange->exchange_id]) ? ' — '.__('Owner keys available') : '' }}</option>
                    @endforeach
                </select>
                <x-button>{{ __('Configure exchange') }}</x-button>
            </form>

            @if ($selected !== null)
                @php($current = $saved->firstWhere('exchange_id', $selected->exchange_id))
                @if (($sharedCounts[$selected->exchange_id] ?? 0) > 0)
                    <p class="mt-5 rounded-lg bg-blue-50 p-4 text-blue-900 dark:bg-blue-950 dark:text-blue-100">{{ __('A server owner is sharing credentials for this exchange. You can use this access or enter your own keys below; your own keys take priority.') }}</p>
                @endif
                @if (!$supported)
                    <p class="mt-5 text-sm">{{ __('This adapter does not support read-only API credentials in this form. Wallet private keys are not accepted.') }}</p>
                @else
                    <x-form method="put" action="{{ route('settings.exchange-keys.update', $selected) }}" class="mt-6 space-y-5" autocomplete="off">
                        <x-heading>{{ $selected->name }}</x-heading>
                        @if ($current)
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Keys are saved. Leave all credential fields blank to keep them. To replace them, enter every required field again. Remove and re-add keys to clear optional fields.') }}</p>
                        @endif
                        @if ($selected->class === 'coinbase')
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('For Coinbase Advanced Trade, enter the full CDP API key name and ECDSA signing key, including its BEGIN/END lines. Preserve the line breaks. Select only the minimum read permissions.') }}</p>
                        @endif
                        @foreach ($required as $field)
                            <div>
                                <label for="credential-{{ $field }}" class="block text-sm font-medium">{{ __($fields[$field]) }}{{ $current ? '' : ' *' }}</label>
                                @if ($field === 'secret' && $selected->class === 'coinbase')
                                    <textarea id="credential-{{ $field }}" name="credentials[{{ $field }}]" rows="5" autocomplete="off" spellcheck="false" @required(!$current) class="mt-1 w-full rounded-lg border border-gray-300 bg-white p-2 font-mono text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                @else
                                    <input id="credential-{{ $field }}" name="credentials[{{ $field }}]" type="password" value="" autocomplete="new-password" spellcheck="false" @required(!$current) class="mt-1 w-full rounded-lg border border-gray-300 bg-white p-2 text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                @endif
                                <x-error :for="'credentials.'.$field" />
                            </div>
                        @endforeach
                        <x-error for="credentials" />
                        @if ($isOwner)
                            <label class="flex items-start gap-3">
                                <input type="checkbox" name="is_shared" value="1" @checked(old('is_shared', $current?->is_shared ?? false)) class="mt-1">
                                <span>{{ __('Share these keys with other users for public market-data collection. The keys will remain hidden.') }}</span>
                            </label>
                        @endif
                        <x-error for="is_shared" />
                        <label class="flex items-start gap-3">
                            <input type="checkbox" name="read_only_confirmed" value="1" required class="mt-1">
                            <span class="text-sm">{{ __('I confirm these keys are read-only, cannot trade or transfer funds, and are restricted to public information wherever supported.') }}</span>
                        </label>
                        <x-error for="read_only_confirmed" />
                        <x-button>{{ __('Save exchange keys') }}</x-button>
                    </x-form>
                @endif
            @endif
        </x-settings.layout>
    </section>
</x-layouts.app>
