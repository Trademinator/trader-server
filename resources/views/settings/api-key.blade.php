<x-layouts.app :title="__('API Key | Settings')">
<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Update API key')" :subheading="__('Use a UUID API key and keep it private. Existing integrations must be updated after rotating it.')">
        <x-form method="put" action="{{ route('settings.api-key.update') }}" class="mt-6 space-y-6">
            @if ($user->api_key)
                <x-input
                    type="text"
                    name="current_api_key"
                    :label="__('Current API key')"
                    required
                    autocomplete="off"
                />
            @endif

            <x-input
                type="text"
                name="api_key"
                :label="__('New API key')"
                required
                autocomplete="off"
            />
            <x-input
                type="text"
                name="api_key_confirmation"
                :label="__('Confirm API key')"
                required
                autocomplete="off"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <x-button class="w-full">{{ __('Save') }}</x-button>
                </div>

                <x-action-message class="me-3" on="api-key-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </x-form>
    </x-settings.layout>
</section>
</x-layouts.app>
