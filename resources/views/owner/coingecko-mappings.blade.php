<x-owner.layout title="CoinGecko mappings">
    <section class="owner-panel">
        @if (session('status')) <p class="mb-4 text-green-600">{{ session('status') }}</p> @endif
        @if ($errors->any()) <p class="mb-4 text-red-600">{{ $errors->first() }}</p> @endif
        <div class="overflow-x-auto bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">Only CoinGecko exceptions appear here. Unsupported quote currencies cannot be solved by choosing a different coin. Deleting an exception does not delete its exchange market, and automatic reconciliation may recreate it.</p>
            <table class="min-w-full text-sm text-gray-900 dark:text-gray-100">
                <thead><tr class="text-left border-b dark:border-gray-600"><th class="p-2">Exchange</th><th class="p-2">Market</th><th class="p-2">Problem</th><th class="p-2">CoinGecko asset</th><th class="p-2">Actions</th></tr></thead>
                <tbody>
                @forelse ($mappings as $mapping)
                    <tr class="border-b dark:border-gray-700">
                        <td class="p-2">{{ $mapping->market?->exchange?->name ?? $mapping->market?->exchange?->class }}</td>
                        <td class="p-2 font-mono">{{ $mapping->market?->symbol }}</td>
                        <td class="p-2"><strong>{{ $mapping->status }}</strong><div class="text-xs text-gray-500 dark:text-gray-400">{{ $mapping->last_error }}</div></td>
                        <td class="p-2">
                            @if (in_array($mapping->status, ['unmapped', 'ambiguous'], true))
                                <form id="map-{{ $mapping->getKey() }}" method="POST" action="{{ route('owner.coingecko-mappings.update', $mapping) }}">@csrf @method('PUT')
                                    <input name="coin_id" list="options-{{ $mapping->getKey() }}" required placeholder="Search by name, ticker or ID" autocomplete="off" class="w-64 rounded dark:bg-gray-700" data-coingecko-search data-options="options-{{ $mapping->getKey() }}" aria-label="CoinGecko coin ID for {{ $mapping->market?->symbol }}" />
                                    <datalist id="options-{{ $mapping->getKey() }}"></datalist>
                                </form>
                            @else <span class="text-gray-500">Quote unsupported; no coin override</span> @endif
                        </td>
                        <td class="p-2 whitespace-nowrap">
                            @if (in_array($mapping->status, ['unmapped', 'ambiguous'], true)) <button class="underline mr-3" type="submit" form="map-{{ $mapping->getKey() }}">Save</button> @endif
                            <form class="inline" action="{{ route('owner.coingecko-mappings.destroy', $mapping) }}" method="POST" onsubmit="return confirm('Delete this mapping exception? It may be recreated automatically.')">@csrf @method('DELETE')<button class="underline text-red-600" type="submit">Delete</button></form>
                        </td>
                    </tr>
                @empty <tr><td class="p-4" colspan="5">No CoinGecko mappings currently require attention.</td></tr> @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $mappings->links() }}</div>
        </div>
    </section>
    <script>
    (() => {
        const url = @json(route('owner.coingecko-mappings.coins'));
        document.querySelectorAll('[data-coingecko-search]').forEach(input => {
            let timer;
            input.addEventListener('input', () => {
                clearTimeout(timer);
                const q = input.value.trim();
                if (q.length < 2) return;
                timer = setTimeout(async () => {
                    const response = await fetch(url + '?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}});
                    if (!response.ok) return;
                    const data = await response.json();
                    const list = document.getElementById(input.dataset.options);
                    list.replaceChildren();
                    for (const coin of data.results || []) {
                        const opt = document.createElement('option');
                        opt.value = coin.id;
                        opt.label = coin.text;
                        list.append(opt);
                    }
                }, 300);
            });
        });
    })();
    </script>
</x-owner.layout>
