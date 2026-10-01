<div class="mb-4 flex flex-wrap items-center justify-end gap-2 text-sm" data-time-display data-user="{{ auth()->id() }}" data-timezone="{{ auth()->user()?->timezone }}" data-timezones="{{ json_encode(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL), JSON_THROW_ON_ERROR) }}">
    <span>{{ __('Display time') }}</span>
    <div role="group" aria-label="{{ __('Display timezone') }}" class="inline-flex gap-1 rounded-lg border border-gray-300 p-1 dark:border-gray-600">
        @foreach (['local' => __('Local'), 'utc' => __('UTC')] as $mode => $label)
            <button type="button" data-time-mode="{{ $mode }}" aria-pressed="{{ $mode === 'local' ? 'true' : 'false' }}" class="min-h-9 rounded-md px-3 py-1 font-medium hover:bg-gray-100 aria-pressed:bg-blue-700 aria-pressed:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:hover:bg-gray-700 dark:aria-pressed:bg-blue-600">{{ $label }}</button>
        @endforeach
    </div>
    <a href="{{ route('settings.profile.edit') }}" class="break-all underline underline-offset-2" title="{{ __('Change your timezone in Profile') }}"><x-timezone-label /></a>
    <noscript><span>{{ __('Enable JavaScript to switch display time. Select your timezone in Profile for local times without JavaScript.') }}</span></noscript>
</div>
