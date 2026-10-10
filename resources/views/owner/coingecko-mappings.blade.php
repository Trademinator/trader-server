<x-owner.layout title="CoinGecko mappings">
    @if (session('coingecko_mapping_feedback'))
        @php
            $feedback = session('coingecko_mapping_feedback');
        @endphp
        <section class="owner-panel" role="status" aria-live="polite" data-coingecko-mapping-feedback>
            <div class="owner-notice">
                <strong>Mapping saved: {{ $feedback['ticker'] }} → {{ $feedback['coin'] }} ({{ $feedback['coin_id'] }})</strong>
                <p>{{ $feedback['message'] }}</p>
                <p class="text-sm">Total saved: {{ $feedback['total'] }} {{ $feedback['total'] === 1 ? 'market' : 'markets' }} · Additional markets updated: {{ $feedback['additional'] }}.</p>
                <p class="text-sm">The checkbox is a one-time action and resets after each submission.</p>
            </div>
        </section>
    @endif
    <section class="owner-panel">
        <div class="overflow-x-auto bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">Only mappings requiring human intervention are shown. The exchange's <strong>base symbol</strong> is the coin we could not identify automatically; the quote is the currency after the slash. Manually resolved rows stay here so you can correct mistakes. Unsupported quote currencies cannot be fixed by choosing another coin.</p>
            <table class="min-w-full text-sm text-gray-900 dark:text-gray-100">
                <thead><tr class="text-left border-b dark:border-gray-600"><th class="p-2">Exchange</th><th class="p-2">Market</th><th class="p-2">Symbol needing identification</th><th class="p-2">Problem / candidates</th><th class="p-2">CoinGecko asset ID</th><th class="p-2">Actions</th></tr></thead>
                <tbody>
                @forelse ($mappings as $mapping)
                    @php
                        $canEdit = in_array($mapping->status, ['unmapped', 'ambiguous'], true) || ($mapping->status === 'resolved' && $mapping->manually_mapped);
                        $symbol = $mapping->base_symbol ?: explode('/', $mapping->market?->symbol ?? '')[0];
                    @endphp
                    <tr class="border-b dark:border-gray-700 align-top">
                        <td class="p-2">{{ $mapping->market?->exchange?->name ?? $mapping->market?->exchange?->class }}</td>
                        <td class="p-2 font-mono">{{ $mapping->market?->symbol }}</td>
                        <td class="p-2"><strong class="font-mono">{{ $mapping->status === 'unsupported' ? strtoupper((string) $mapping->vs_currency) : $symbol }}</strong><div class="text-xs text-gray-500">{{ $mapping->status === 'unsupported' ? 'Unsupported quote currency' : 'Exchange base ticker' }}</div></td>
                        <td class="p-2"><strong>{{ $mapping->manually_mapped ? 'Manually mapped' : $mapping->status }}</strong>
                            @if (! $mapping->manually_mapped && $mapping->last_error)<div class="text-xs text-gray-500 dark:text-gray-400">{{ $mapping->last_error }}</div>@endif
                            @if ($mapping->status === 'ambiguous')
                                <div class="mt-2 text-xs" data-coingecko-candidates data-symbol="{{ $symbol }}" data-input="coin-{{ $mapping->getKey() }}" aria-live="polite">Loading exact CoinGecko symbol matches…</div>
                            @endif
                        </td>
                        <td class="p-2">
                            @if ($canEdit)
                                <form id="map-{{ $mapping->getKey() }}" method="POST" action="{{ route('owner.coingecko-mappings.update', $mapping) }}">
                                    @csrf @method('PUT')
                                    <input id="coin-{{ $mapping->getKey() }}" name="coin_id" value="{{ $mapping->coin_id }}" list="options-{{ $mapping->getKey() }}" required placeholder="Search coin name, ticker or API ID" autocomplete="off" class="w-64 rounded dark:bg-gray-700" data-coingecko-search data-options="options-{{ $mapping->getKey() }}" aria-label="CoinGecko API ID for {{ $symbol }} on {{ $mapping->market?->exchange?->class }}" />
                                    <datalist id="options-{{ $mapping->getKey() }}"></datalist>
                                    @if($mapping->manually_mapped)<div class="text-xs text-gray-500 mt-1">Current: {{ $mapping->coin_name }} · {{ $mapping->coin_id }}</div>@endif
                                    <label class="flex items-start gap-2 text-xs mt-2 max-w-sm">
                                        <input type="checkbox" name="apply_same_base" value="1" class="mt-0.5">
                                        <span>Also map unresolved {{ strtoupper((string) $symbol) }} spot markets across exchanges, using only supported quote currencies. Existing resolved mappings will not be changed.</span>
                                    </label>
                                </form>
                            @else
                                <span class="text-gray-500">No coin override for unsupported quotes</span>
                            @endif
                        </td>
                        <td class="p-2 whitespace-nowrap">
                            @if ($canEdit)<button class="underline mr-3" type="submit" form="map-{{ $mapping->getKey() }}">{{ $mapping->manually_mapped ? 'Update mapping' : 'Save mapping' }}</button>@endif
                            <form class="inline" action="{{ route('owner.coingecko-mappings.destroy', $mapping) }}" method="POST" onsubmit="return confirm('Delete this mapping? Automatic reconciliation may recreate it.')">@csrf @method('DELETE')<button class="underline text-red-600" type="submit">Delete</button></form>
                        </td>
                    </tr>
                @empty <tr><td class="p-4" colspan="6">No CoinGecko mappings currently require attention.</td></tr> @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $mappings->links() }}</div>
        </div>
    </section>
    <script>
    (() => {
        const url = @json(route('owner.coingecko-mappings.coins'));
        const lookup = async (q, exact = false) => {
            const response = await fetch(url + '?q=' + encodeURIComponent(q) + (exact ? '&exact=1' : ''), {headers: {'Accept': 'application/json'}});
            if (!response.ok) throw new Error('CoinGecko lookup failed');
            return (await response.json()).results || [];
        };
        document.querySelectorAll('[data-coingecko-candidates]').forEach(async area => {
            try {
                const choices = await lookup(area.dataset.symbol, true);
                area.replaceChildren();
                if (!choices.length) { area.textContent = 'No exact ticker matches found. Use the search box to find a coin by name or ID.'; return; }
                const heading = document.createElement('div');
                heading.textContent = 'CoinGecko exact ticker matches (' + choices.length + (choices.length === 50 ? '+' : '') + '):';
                area.append(heading);
                const list = document.createElement('ul');
                for (const coin of choices) {
                    const item = document.createElement('li');
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'underline text-left';
                    button.textContent = coin.text;
                    button.addEventListener('click', () => { const field = document.getElementById(area.dataset.input); if (field) field.value = coin.id; });
                    item.append(button); list.append(item);
                }
                area.append(list);
            } catch (_) { area.textContent = 'Unable to load candidates; search the catalogue manually.'; }
        });
        document.querySelectorAll('[data-coingecko-search]').forEach(input => {
            let timer;
            input.addEventListener('input', () => {
                clearTimeout(timer);
                const q = input.value.trim();
                if (q.length < 2) return;
                timer = setTimeout(async () => {
                    try {
                        const choices = await lookup(q);
                        const list = document.getElementById(input.dataset.options);
                        list.replaceChildren();
                        for (const coin of choices) {
                            const opt = document.createElement('option');
                            opt.value = coin.id; opt.label = coin.text; list.append(opt);
                        }
                    } catch (_) { /* Keep field editable when API is unavailable. */ }
                }, 300);
            });
        });
    })();
    </script>
</x-owner.layout>
