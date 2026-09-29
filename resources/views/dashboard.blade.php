<x-layouts.app :title="__('Dashboard')">
    @php
        $time = fn ($ms) => $ms === null ? 'Not available' : gmdate('Y-m-d H:i:s', (int) ($ms / 1000)).' UTC';
        $number = fn ($value, $digits = 2) => $value === null ? 'Unknown' : number_format($value, $digits);
    @endphp
    @include('markets.guide-styles')
    @include('markets.intelligence-styles')
    <div class="dashboard-page flex flex-col gap-5">
        <header class="dashboard-hero">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div><p class="dashboard-eyebrow">Your market intelligence</p><h1>Dashboard</h1>
                    <p>Follow markets, understand the evidence, and see what changed.</p></div>
                <a class="dashboard-button dashboard-button-light" href="{{ route('markets.index') }}">Manage subscriptions</a>
            </div>
            <p class="dashboard-contract">A subscription lets you follow a market and is required for the Client to trade it. Following a market does not enable trading; the Client decides whether to act.</p>
        </header>
        @if (session('status'))<p class="guide-notice" role="status">{{ session('status') }}</p>@endif
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Dashboard overview">
            <a class="dashboard-stat" href="#subscriptions"><span>Markets you follow</span><strong>{{ $subscriptions->total() }}</strong><small>Active subscriptions</small></a>
            <a class="dashboard-stat" href="#subscriptions"><span>Validated models</span><strong>{{ $cards->where('ready', true)->count() }} <small>/ {{ $cards->count() }}</small></strong><small>For the markets on this page</small></a>
            <a class="dashboard-stat" href="#attention"><span>Needs attention</span><strong>{{ $cards->where('attention', true)->count() }}</strong><small>Collection or history on this page</small></a>
            <a class="dashboard-stat" href="#changes"><span>New signal changes</span><strong>{{ $changeCount }}</strong><small>Since {{ $time($since) }}</small></a>
        </div>
        <section class="guide-panel" aria-labelledby="conditions-title">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="conditions-title">Market conditions</h2><span class="guide-badge">CoinGecko context</span></div>
            @if ($conditions)
                <div class="grid gap-4 sm:grid-cols-3 mt-3">
                    <p><span class="dashboard-muted">Global market cap · 24h</span><br><strong>{{ $number($conditions['market_change_24h']) }}%</strong></p>
                    <p><span class="dashboard-muted">Bitcoin dominance</span><br><strong>{{ $number($conditions['btc_dominance']) }}%</strong></p>
                    <p><span class="dashboard-muted">Global volume · 24h</span><br><strong>{{ $number($conditions['volume_usd'] === null ? null : $conditions['volume_usd'] / 1000000000) }} billion USD</strong></p>
                </div>
                <p class="guide-help">Observed {{ $time($conditions['observed_at_ms']) }}. Broad market context; chart prices below come from the selected exchange.</p>
                @if ($conditions['categories'])<div class="flex flex-wrap gap-2 mt-3" aria-label="Largest category movements in the available sample">@foreach ($conditions['categories'] as $category)
                    <span class="guide-badge">{{ $category['name'] }} · {{ $number($category['change_24h']) }}% market cap / 24h</span>
                @endforeach</div>@endif
            @else
                <p>Fresh market context is not available. Your subscribed-market charts and intelligence remain available below.</p>
            @endif
        </section>
        <section id="subscriptions" aria-labelledby="subscriptions-title">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3"><h2 id="subscriptions-title" class="text-xl font-semibold">Markets you follow</h2><span class="dashboard-muted">Choose a market to inspect its chart and readiness</span></div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($cards as $card)
                    @php($subscription = $card['subscription'])
                    @php($market = $subscription->market)
                    <a class="dashboard-market {{ $card['ready'] ? 'has-validated-model' : '' }} {{ $details && $details['subscription']->getKey() === $subscription->getKey() ? 'is-selected' : '' }}" href="{{ route('dashboard', ['subscription' => $subscription->getKey(), 'page' => $subscriptions->currentPage()]) }}#market-detail">
                        <div class="flex items-start justify-between gap-3"><div><h3>{{ $market->symbol }}</h3><p>{{ $market->exchange->name }} · {{ $market->feed?->selected_period ?? 'Selecting period' }}</p></div><span class="guide-badge">Following</span></div>
                        @if ($card['sparkline'])<svg viewBox="0 0 200 48" role="img" aria-label="Recent closed price history for {{ $market->symbol }}" class="dashboard-sparkline"><polyline points="{{ $card['sparkline'] }}" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" /></svg>
                        @else<p class="dashboard-muted my-3">Chart awaiting continuous price history</p>@endif
                        <div class="flex flex-wrap justify-between gap-2"><strong>{{ $card['label'] }}</strong><span>{{ $card['ready'] ? 'Model validated' : 'Learning / awaiting validation' }}</span></div>
                        @if ($card['attention'])<p class="dashboard-warning">Collection or history needs attention</p>@endif
                        <p class="guide-help">Last candle closed: {{ $time($card['chart']['last_closed_at_ms']) }}</p>
                        @if ($card['ready'])<span class="dashboard-validated-check" role="img" aria-label="Validated model" title="Validated model">✓</span>@endif
                    </a>
                @empty
                    <div class="guide-panel md:col-span-2 xl:col-span-3"><h3>Start following a market</h3><p>Subscribe to collect its history and follow its intelligence. You can observe without trading.</p><a class="dashboard-button mt-3" href="{{ route('markets.index') }}">Choose a market</a></div>
                @endforelse
            </div>
            <div class="mt-4">{{ $subscriptions->links() }}</div>
        </section>
        <section id="attention" class="guide-panel" aria-labelledby="attention-title">
            <h2 id="attention-title">Needs attention</h2>
            @forelse ($cards->where('attention', true) as $card)
                <p class="dashboard-attention-row"><a href="{{ route('dashboard', ['subscription' => $card['subscription']->getKey()]) }}#market-detail">{{ $card['subscription']->market->exchange->name }} · {{ $card['subscription']->market->symbol }}</a>
                    <span>{{ $card['chart']['last_closed_at_ms'] === null ? 'Waiting for closed candles.' : ($card['chart']['stale'] ? 'Price history is stale.' : 'Review collection and history quality.') }} Open the readiness details below.</span></p>
            @empty<p>No collection problems detected for the markets shown.</p>@endforelse
            @if ($cards->where('ready', false)->count())<p class="guide-help">{{ $cards->where('ready', false)->count() }} shown markets are still awaiting a current validated model. Select a market to see the missing evidence.</p>@endif
        </section>
        @if ($details)
            @php($selected = $details['subscription'])
            @php($market = $selected->market)
            @php($progress = $details['progress'])
            @php($chart = $details['chart'])
            <section id="market-detail" class="guide-panel" aria-labelledby="market-title">
                <div class="flex flex-wrap justify-between items-start gap-3"><div><h2 id="market-title">{{ $market->symbol }} · {{ $market->exchange->name }}</h2><p>Closed {{ $chart['period'] ?? 'pending' }} candles · {{ explode('/', $market->symbol)[1] ?? '' }} per {{ explode('/', $market->symbol)[0] }} · UTC</p></div>
                    <a class="dashboard-button" href="{{ route('markets.intelligence', $selected->getKey()) }}">Full intelligence report</a></div>
                <div data-dashboard-chart data-url="{{ route('dashboard.chart', $selected->getKey()) }}" data-subscription="{{ $selected->getKey() }}" data-chart="{{ json_encode($chart, JSON_THROW_ON_ERROR) }}" data-tick-size="{{ $market->tick_size }}">
                    <div class="flex flex-wrap items-center gap-4 my-3"><button type="button" class="dashboard-button" data-refresh>Refresh chart</button><button type="button" class="dashboard-button" data-fit>Fit candles</button><label><input type="checkbox" data-markers checked> Show Server signals</label><label><input type="checkbox" data-auto checked> Refresh every minute</label></div>
                    <p class="guide-help" role="status" aria-live="polite" data-status>Loading chart…</p><p class="guide-help" data-legend>Closed exchange candles and recorded Server signals.</p>
                    <div class="dashboard-chart" data-canvas role="img" aria-label="{{ $market->symbol }} price history with recorded Server signal markers"></div>
                    <noscript><p>Enable JavaScript for the interactive chart. Recent candle values and the signal journal remain available.</p></noscript>
                </div>
                <p class="guide-help">↑ BUY · ↓ SELL · ● HOLD · ■ Waiting for evidence. These are Server observations. Client execution is unknown.</p>
                <p class="guide-help">Markers appear at the first candle opening at or after the signal was recorded, once that candle closes. Exact recording and source times are in the journal. Historical signals are never recalculated with a newer model.</p>
                <p class="guide-help">TradingView Lightweight Charts™ · <a href="https://www.tradingview.com/" target="_blank" rel="noopener noreferrer">TradingView</a> · Market data collected by Trademinator.</p>
                <details class="mt-3"><summary>Recent closed candle values</summary><div class="dashboard-table-wrap"><table><thead><tr><th>Open time (UTC)</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Volume</th></tr></thead><tbody>
                    @forelse (array_reverse(array_slice($chart['series'], -10)) as $candle)<tr><td>{{ $time($candle['time'] * 1000) }}</td><td>{{ $number($candle['open'], 8) }}</td><td>{{ $number($candle['high'], 8) }}</td><td>{{ $number($candle['low'], 8) }}</td><td>{{ $number($candle['close'], 8) }}</td><td>{{ $number($candle['volume'], 8) }}</td></tr>@empty<tr><td colspan="6">No closed candles yet.</td></tr>@endforelse
                </tbody></table></div></details>
            </section>
            <section class="guide-panel" aria-labelledby="readiness-title">
                <h2 id="readiness-title">Intelligence readiness · {{ $market->symbol }}</h2>
                <p>{{ $progress['action'] }}</p>
                <div class="grid gap-4 lg:grid-cols-3 mt-4">
                    <div><h3>1. Usable history</h3>
                        @if ($progress['history'])<x-intelligence-progress label="Potential training rows" :value="$progress['history']['potential']" :target="$progress['minimum']" native />
                        @else<p>Waiting for a selected candle period and complete features.</p>@endif
                        <p class="guide-help">Estimate before source checks and model-specific exclusions.</p></div>
                    <div><h3>2. Model build</h3><p>{{ $details['report'] ? 'A model build has completed.' : 'No model build recorded yet.' }}</p>
                        @if ($details['report'])<p class="guide-help">Model {{ $details['report']['model_id'] }}</p>@endif</div>
                    <div><h3>3. Validation</h3><p>{{ ($details['report']['status'] ?? null) === 'ready' ? 'The recorded model passed its validation gates.' : 'Awaiting a model that passes validation.' }}</p><p class="guide-help">Enough rows permit evaluation; they do not guarantee a usable signal.</p></div>
                </div>
                @if ($progress['eta'])<p><strong>Earliest data estimate: {{ $progress['eta']->utc()->format('Y-m-d H:i:s') }} UTC</strong></p>@endif
                <p class="guide-help">{{ $progress['eta_note'] }} Validated-model ETA: unknown.</p>
                @if ($progress['issues'])<ul class="list-disc pl-5 mt-3">@foreach ($progress['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>@endif
                @if ($details['signal'] && $details['signal_fresh'])
                    <div class="guide-notice mt-4"><strong>{{ \App\Domain\Intelligence\SignalJournal::label($details['signal']->action, $details['signal']->reason) }}</strong> · {{ $details['signal']->payload['explanation'] ?? \App\Domain\Intelligence\SignalJournal::explain($details['signal']->reason) }}
                        @if ($details['signal']->reason === 'supported')<p>Confidence score: {{ $number(($details['signal']->payload['confidence'] ?? 0) * 100, 1) }}% · Effective neighbors: {{ $number($details['signal']->payload['effective_neighbors'] ?? null, 1) }}. Confidence is an evidence score, not a probability of profit.</p>@endif</div>
                @else<p class="guide-notice mt-4">No current recorded signal is available. Collection and the scheduled signal recorder must run before a fresh observation appears.</p>@endif
            </section>
            <section class="guide-panel" aria-labelledby="journal-title"><h2 id="journal-title">Recorded signal journal · {{ $market->symbol }}</h2><p>Latest 20 observations, including deliberate HOLD decisions and insufficient evidence. An observation is not a trade.</p>
                <div class="dashboard-table-wrap"><table><thead><tr><th>Recorded (UTC)</th><th>Server decision</th><th>Explanation and evidence</th><th>Client execution</th></tr></thead><tbody>
                    @forelse ($details['history'] as $signal)<tr><td>{{ $time($signal->recorded_at_ms) }}</td><td>{{ \App\Domain\Intelligence\SignalJournal::label($signal->action, $signal->reason) }}</td><td>{{ $signal->payload['explanation'] ?? \App\Domain\Intelligence\SignalJournal::explain($signal->reason) }}<details><summary>Trace this observation</summary><p>Source candle closed: {{ $time($signal->decision_at_ms) }}<br>Period: {{ $signal->period }}<br>Model: {{ $signal->model_id ?? 'None' }}<br>Signal: {{ $signal->getKey() }}<br>Horizon: {{ $signal->payload['horizon_candles'] ?? 'Unknown' }} candles<br>Confidence score: {{ $signal->reason === 'supported' ? $number(($signal->payload['confidence'] ?? 0) * 100, 1).'%' : 'Not supported' }}</p></details></td><td>Unknown</td></tr>
                    @empty<tr><td colspan="4">No signals recorded yet. History starts when the signal recorder runs; past decisions are not invented.</td></tr>@endforelse
                </tbody></table></div>
            </section>
        @endif
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="guide-panel" id="changes" aria-labelledby="changes-title"><h2 id="changes-title">Changes since your last visit</h2><p class="guide-help">Since {{ $time($since) }} · Showing up to 20 changes across active subscriptions.</p>
                @forelse ($timeline as $event)<p class="dashboard-attention-row"><strong>{{ $event->market->symbol }} · {{ $event->market->exchange->name }}</strong><span>{{ \App\Domain\Intelligence\SignalJournal::label($event->action, $event->reason) }} · {{ $time($event->recorded_at_ms) }}</span></p>@empty<p>No new recorded signal changes. Normal collection can continue without a directional signal.</p>@endforelse
            </section>
            <section class="guide-panel" aria-labelledby="coverage-title"><h2 id="coverage-title">Subscription coverage</h2>
                @forelse ($overlap as $asset => $count)<p>{{ $asset }} appears as the base asset in {{ $count }} markets on this page.</p>@empty<p>No repeated base assets among the markets shown.</p>@endforelse
                <p class="guide-help">This describes the markets you follow. Your holdings, allocations, and trading activity are known to the Client.</p>
            </section>
        </div>
        <section class="guide-panel" id="suggestions" aria-labelledby="suggestions-title"><div class="flex flex-wrap justify-between gap-3"><h2 id="suggestions-title">Suggested markets to follow</h2><a href="{{ route('markets.suggestions') }}" class="dashboard-link">Edit preferences</a></div>
            <p>Suggestions combine your saved answers with available market evidence. Review a market before choosing to subscribe.</p>
            <div data-dashboard-suggestions data-url="{{ route('dashboard.suggestions') }}"><p class="guide-help" role="status">Loading your suggestions…</p><a href="{{ route('markets.suggestions', ['show' => 1]) }}">Open suggestions</a></div>
        </section>
    </div>
</x-layouts.app>
