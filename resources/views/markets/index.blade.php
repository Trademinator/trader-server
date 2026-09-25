<x-layouts.app :title="__('Market subscriptions')">
    <section class="max-w-4xl space-y-8">
        <header>
            <h1 class="text-2xl font-semibold">Market subscriptions</h1>
            <p class="mt-2">Watch an exchange and trading pair. A shared feed collects its candles for everyone subscribed to that market.</p>
        </header>

        @if (session('status'))
            <p role="status" class="rounded-lg bg-green-100 p-3 text-green-900">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('markets.store') }}" class="space-y-4 rounded-lg border border-gray-300 p-5 dark:border-gray-600">
            @csrf
            <h2 class="text-lg font-medium">Add a market</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <label class="block">Exchange ID
                    <input name="exchange" value="{{ old('exchange') }}" required maxlength="32" placeholder="kraken" class="mt-1 w-full rounded border p-2 text-gray-900">
                </label>
                <label class="block">Symbol
                    <input name="symbol" value="{{ old('symbol') }}" required maxlength="32" placeholder="BTC/USD" class="mt-1 w-full rounded border p-2 text-gray-900">
                </label>
                <label class="block">Price tick size
                    <input name="tick_size" value="{{ old('tick_size') }}" required placeholder="0.01" inputmode="decimal" class="mt-1 w-full rounded border p-2 text-gray-900">
                </label>
            </div>
            <p class="text-sm">Use the exchange's CCXT ID and exact symbol. Tick size is the smallest price increment for that market.</p>
            @if ($errors->any())
                <div role="alert" class="text-red-600"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <button type="submit" class="rounded bg-blue-700 px-4 py-2 text-white">Subscribe</button>
        </form>

        <div class="space-y-3">
            <h2 class="text-lg font-medium">Your markets</h2>
            @forelse ($subscriptions as $subscription)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-300 p-4 dark:border-gray-600">
                    <div>
                        <strong>{{ $subscription->market->exchange->name }} · {{ $subscription->market->symbol }}</strong>
                        <p class="text-sm">{{ $subscription->active ? 'Active' : 'Inactive' }} · Feed: {{ $subscription->market->feed->status ?? 'pending' }} · Period: {{ $subscription->market->feed->selected_period ?? 'selecting' }}</p>
                    </div>
                    @if ($subscription->active)
                        <form method="POST" action="{{ route('markets.destroy', $subscription->market_subscription_id) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="rounded border px-3 py-2">Unsubscribe</button>
                        </form>
                    @endif
                </div>
            @empty
                <p>No market subscriptions yet.</p>
            @endforelse
        </div>
    </section>
</x-layouts.app>
