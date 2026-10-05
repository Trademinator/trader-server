<x-layouts.auth :title="__('Confirm password')">
<div class="space-y-6">
    <x-auth-header
        :title="__('Confirm password')"
        :description="__('This is a secure area of the application. Please confirm your password before continuing.')"
    />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <x-form method="post" action="{{ $formAction ?? route('confirmation.store') }}" class="space-y-6">
        @foreach ($formFields ?? [] as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <!-- Password -->
        <x-input
            type="password"
            :label="__('Password')"
            name="password"
            required
            autocomplete="current-password"
        />

        <x-button class="w-full">{{ $confirmLabel ?? __('Confirm') }}</x-button>
    </x-form>
</div>
</x-layouts.auth>
