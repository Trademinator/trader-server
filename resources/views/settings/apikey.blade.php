<x-layouts.app :title="__('Password | Settings')">
<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Update API key')" :subheading="__('Ensure your account is using a long, random key to stay secure')">
        <x-form method="put" action="{{ route('settings.apikey.update') }}" class="mt-6 space-y-6">
            <x-input
                type="text"
                name="current_apikey"
                :label="__('Current API key')"
                required
                autocomplete="current-apikey"
                :value="$user->api_key"
            />
            <x-input
                type="text"
                name="apikey"
                :label="__('New API key')"
                required
                autocomplete="new-apikey"
            />
            <x-input
                type="text"
                name="apikey_confirmation"
                :label="__('Confirm API key')"
                required
                autocomplete="new-apikey"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <x-button class="w-full">{{ __('Save') }}</x-button>
                </div>

                <x-action-message class="me-3" on="apikey-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </x-form>
    </x-settings.layout>
</section>
</x-layouts.app>
