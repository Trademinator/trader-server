<div class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
    @if (config('app.logo_url'))
        <img src="{{ config('app.logo_url') }}" alt="" class="size-5 object-contain">
    @else
        <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
    @endif
</div>
<div class="ml-1 grid flex-1 text-left text-sm">
    <span class="mb-0.5 truncate leading-none font-semibold">{{ config('app.name') }}</span>
</div>
