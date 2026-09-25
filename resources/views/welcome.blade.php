<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
        <div class="mx-auto flex min-h-svh w-full max-w-5xl flex-col px-6 py-6 sm:px-10">
            <header class="flex flex-wrap items-center justify-between gap-4">
                <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold" aria-label="{{ config('app.name') }}">
                    <x-app-logo-icon class="size-10 rounded-md" />
                    <span>{{ config('app.name') }}</span>
                </a>
                <nav class="flex items-center gap-4 text-sm" aria-label="{{ __('Account') }}">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-md border border-gray-300 px-4 py-2 font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-900">{{ __('Dashboard') }}</a>
                    @else
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" class="rounded-md px-4 py-2 font-medium hover:bg-gray-100 dark:hover:bg-gray-900">{{ __('Log in') }}</a>
                        @endif
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="rounded-md border border-gray-300 px-4 py-2 font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-900">{{ __('Register') }}</a>
                        @endif
                    @endauth
                </nav>
            </header>

            <main class="grid flex-1 items-center gap-10 py-12 lg:grid-cols-2">
                <div class="mx-auto w-full max-w-sm overflow-hidden rounded-2xl bg-black">
                    <x-app-logo-icon variant="full" :alt="config('app.name').' logo'" class="h-auto w-full" fetchpriority="high" />
                </div>
                <div class="max-w-lg space-y-6">
                    <p class="text-sm font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">{{ config('app.name') }}</p>
                    <h1 class="text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">{{ __('Your markets. Your perspective.') }}</h1>
                    <p class="text-lg leading-relaxed text-gray-600 dark:text-gray-300">{{ __('Follow your exchange pairs and build your market analysis in one place.') }}</p>
                    <div class="flex flex-wrap items-center gap-4">
                        @auth
                            <a href="{{ route('markets.index') }}" class="rounded-lg bg-gray-900 px-6 py-3 font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-950 dark:hover:bg-white">{{ __('Your markets') }}</a>
                        @else
                            @if (Route::has('login'))
                                <a href="{{ route('login') }}" class="rounded-lg bg-gray-900 px-6 py-3 font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-950 dark:hover:bg-white">{{ __('Log in') }}</a>
                            @endif
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="rounded-lg border border-gray-300 px-6 py-3 font-semibold hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-900">{{ __('Create an account') }}</a>
                            @endif
                        @endauth
                    </div>
                </div>
            </main>
        </div>
    </body>
</html>
