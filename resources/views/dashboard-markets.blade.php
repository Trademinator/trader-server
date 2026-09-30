@php($time = fn ($ms) => $ms === null ? 'Not available' : gmdate('Y-m-d H:i:s', (int) ($ms / 1000)).' UTC')
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($cards as $card)
                    @php($subscription = $card['subscription'])
                    @php($market = $subscription->market)
                    <a class="dashboard-market {{ $card['ready'] ? 'has-validated-model' : '' }} {{ $selectedId === $subscription->getKey() ? 'is-selected' : '' }}" href="{{ route('dashboard', ['subscription' => $subscription->getKey(), 'page' => $subscriptions->currentPage(), 'q' => $search]) }}#market-detail">
                        <div class="flex items-start justify-between gap-3"><div><h3>{{ $market->symbol }}</h3><p class="flex items-center gap-2"><span class="dashboard-exchange-logo" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr($market->exchange->name, 0, 1)) }}</span>@if ($card['logo_url'])<img src="{{ $card['logo_url'] }}" width="24" height="24" loading="lazy" decoding="async" referrerpolicy="no-referrer" alt="">@endif</span>{{ $market->exchange->name }} · {{ $market->feed?->selected_period ?? 'Selecting period' }}</p></div><span class="guide-badge">Following</span></div>
                        @if ($card['sparkline'])<svg viewBox="0 0 200 48" role="img" aria-label="Recent closed price history for {{ $market->symbol }}" class="dashboard-sparkline"><polyline points="{{ $card['sparkline'] }}" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" /></svg>
                        @else<p class="dashboard-muted my-3">Chart awaiting continuous price history</p>@endif
                        <div class="flex flex-wrap justify-between gap-2"><strong>{{ $card['label'] }}</strong><span>{{ $card['ready'] ? 'Model validated' : 'Learning / awaiting validation' }}</span></div>
                        @if ($card['attention'])<p class="dashboard-warning">Collection or history needs attention</p>@endif
                        <p class="guide-help">Last candle closed: {{ $time($card['chart']['last_closed_at_ms']) }}</p>
                        @if ($card['ready'])<span class="dashboard-validated-check" role="img" aria-label="Validated model" title="Validated model">✓</span>@endif
                    </a>
                @empty
                    @if ($search !== '')<div class="guide-panel md:col-span-2 xl:col-span-3"><p>No followed markets match “{{ $search }}”. Try another pair, exchange or period.</p></div>
                    @else<div class="guide-panel md:col-span-2 xl:col-span-3"><h3>Start following a market</h3><p>Subscribe to collect its history and follow its intelligence. You can observe without trading.</p><a class="dashboard-button mt-3" href="{{ route('markets.index') }}">Choose a market</a></div>@endif
                @endforelse
            </div>
            <div class="mt-4" data-market-pagination>{{ $subscriptions->links() }}</div>
        @can('manage-server')
        <section id="attention" class="guide-panel mt-5" aria-labelledby="attention-title">
            <h2 id="attention-title">Needs attention</h2>
            <p class="guide-help">Collection issues for the displayed markets. Run suggested commands from the server's project directory.</p>
            @forelse ($cards->where('attention', true) as $card)
                <div class="dashboard-attention-row">
                    <a href="{{ route('dashboard', ['subscription' => $card['subscription']->getKey()]) }}#market-detail">{{ $card['subscription']->market->exchange->name }} · {{ $card['subscription']->market->symbol }} · {{ $card['subscription']->market->feed?->selected_period ?? 'Period pending' }}</a>
                    @foreach ($card['issues'] as $issue)
                        <div><p>{{ $issue['message'] }}</p>
                            @foreach ($issue['commands'] as $command)<pre class="dashboard-command"><code>{{ $command }}</code></pre>@endforeach
                        </div>
                    @endforeach
                </div>
            @empty<p>No collection problems detected for the displayed markets.</p>@endforelse
        </section>
        @endcan
