<x-layouts.app :title="$item ? 'Review '.$item['symbol'] : 'Pair review'">
    @include('markets.guide-styles')
    <style>
        .pair-review { min-width:0; width:100%; }
        .pair-review .review-topline { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
        .pair-review .review-eyebrow { font-size:.85rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .pair-review .review-title { font-size:2.3rem; letter-spacing:-.025em; }
        .pair-review .review-score { font-size:2.7rem; font-weight:750; line-height:1.2; white-space:nowrap; }
        .pair-review .review-score small { font-size:1rem; font-weight:500; }
        .pair-review .review-metrics { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:18px 0; }
        .pair-review .review-metric { padding:16px; border-radius:10px; background:#edf3fb; color:#172033; }
        .dark .pair-review .review-metric { background:#24334b; color:#f1f5f9; }
        .pair-review .review-metric strong { display:block; font-size:1.65rem; margin:5px 0; }
        .pair-review .review-table-wrap { overflow-x:auto; }
        .pair-review table { width:100%; border-collapse:collapse; font-size:.95rem; }
        .pair-review th, .pair-review td { text-align:left; vertical-align:top; border-bottom:1px solid #b8c4d2; padding:12px 10px; }
        .dark .pair-review th, .dark .pair-review td { border-color:#52647d; }
        .pair-review th { font-weight:700; }
        .pair-review .review-points { white-space:nowrap; font-weight:750; }
        .pair-review .review-formula { padding:14px; border-radius:8px; background:#edf3fb; color:#172033; font-family:ui-monospace,monospace; overflow-wrap:anywhere; }
        .dark .pair-review .review-formula { background:#24334b; color:#f1f5f9; }
        .pair-review dl { display:grid; grid-template-columns:minmax(120px,1fr) minmax(0,2fr); gap:12px; }
        .pair-review dt { font-weight:650; }
        .pair-review dd { overflow-wrap:anywhere; }
        .pair-review .review-chart { height:390px; width:100%; min-width:0; margin:14px 0; }
        .pair-review .review-chart[hidden] { display:none; }
        .pair-review .review-control { border:1px solid #7c8da3; padding:7px 12px; border-radius:7px; font-weight:650; cursor:pointer; }
        .pair-review button:disabled { opacity:.55; cursor:wait; }
        .pair-review .review-legend { min-height:1.6em; font-size:.9rem; font-variant-numeric:tabular-nums; }
        .pair-review .review-subscribe { border-top:4px solid #0756b9; }
        .pair-review .review-subscribe form { margin:14px 0; }
        @media(max-width:650px) { .pair-review .review-metrics { grid-template-columns:1fr; } .pair-review .review-chart { height:300px; } .pair-review .review-title { font-size:1.9rem; } }
    </style>
    <section class="pair-guide pair-review">
        <nav class="review-topline" aria-label="Pair review navigation">
            <a href="{{ route('markets.suggestions', ['show' => 1]) }}">← Back to suggestions</a>
            <a href="{{ route('markets.index') }}">Your markets</a>
        </nav>
        @if ($errors->any())
            <div class="guide-notice guide-error" role="alert"><strong>Could not subscribe.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if ($failure)
            <section class="guide-panel">
                <h1>Pair review unavailable</h1>
                <p class="guide-notice guide-error" role="alert">{{ $failure }}</p>
                @foreach ($results['notes'] ?? [] as $note)<p>{{ $note }}</p>@endforeach
                <a class="guide-button" href="{{ route('markets.suggestions', ['show' => 1]) }}">Review current suggestions</a>
            </section>
        @else
            @php
                $evidence = $item['evidence'];
                $market = $item['market_details'];
                $number = static function ($value) {
                    if ($value === null) { return 'Unknown'; }
                    if ((float) $value === 0.0) { return '0'; }
                    $decimals = min(18, max(1, 8 - (int) floor(log10(abs((float) $value)))));
                    return rtrim(rtrim(number_format((float) $value, $decimals, '.', ','), '0'), '.');
                };
                $rank = array_search($item['symbol'], array_column($results['items'], 'symbol'), true) + 1;
            @endphp
            <header class="guide-hero">
                <div class="review-topline">
                    <div>
                        <p class="review-eyebrow">{{ $exchange->name }} · Spot pair review</p>
                        <h1 class="review-title">Why {{ $item['symbol'] }} fits your preferences</h1>
                        <p>{{ $item['base'] }} is the base asset. Prices show how much {{ $item['quote'] }} buys one {{ $item['base'] }}.</p>
                    </div>
                    <div><div class="review-score">{{ $item['score'] }}<small> / 100 points</small></div><div>Funding &amp; goal match</div></div>
                </div>
                <p><span class="guide-badge {{ $item['explore'] ? '' : 'is-screened' }}">{{ $item['explore'] ? 'Explore only' : 'Matches preference screens' }}</span> · Position {{ $rank }} of {{ count($results['items']) }} in your current shortlist</p>
            </header>

            <section class="guide-panel review-subscribe" aria-labelledby="review-summary">
                <h2 id="review-summary">The match in plain language</h2>
                <ul>@foreach ($item['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                <p>The score measures how this pair connects to your holdings and goal. It is not a percentage probability, expected profit, buy/sell signal or KNN confidence score.</p>
                @if ($item['explore'])
                    <details open><summary>Why this is marked “Explore only”</summary><ul>@foreach ($item['exploration_reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul></details>
                @else
                    <p class="guide-notice">This pair passed the current preference screens using the available sample. Future losses and trading costs remain uncertain.</p>
                @endif
                @if ($item['subscribed'])
                    <p role="status"><strong>Already subscribed</strong> · This pair is in <a href="{{ route('markets.index') }}">your markets</a>.</p>
                @else
                    <form method="POST" action="{{ route('markets.store') }}" data-review-subscribe>
                        @csrf
                        <input type="hidden" name="exchange" value="{{ $exchange->class }}">
                        <input type="hidden" name="symbol" value="{{ $item['symbol'] }}">
                        <button type="submit" class="guide-button">Subscribe to {{ $item['symbol'] }}</button>
                    </form>
                @endif
                <p class="guide-help">Subscribing follows this market and enables shared price-data collection. It does not place a trade. The candle period is selected automatically by Trademinator.</p>
            </section>

            <section class="guide-panel" aria-labelledby="score-heading">
                <h2 id="score-heading">Exactly how your score is calculated</h2>
                <div class="review-table-wrap"><table>
                    <thead><tr><th scope="col">Factor</th><th scope="col">Your points</th><th scope="col">Rule applied</th></tr></thead>
                    <tbody>@foreach ($item['score_breakdown'] as $factor)
                        <tr><th scope="row">{{ $factor['label'] }}</th><td class="review-points">{{ $factor['points'] }} / {{ $factor['maximum'] }}</td><td>{{ $factor['rule'] }}</td></tr>
                    @endforeach</tbody>
                    <tfoot><tr><th scope="row">Total match score</th><td class="review-points">{{ $item['score'] }} / 100</td><td>The four contributions are added. Holding more money does not add points.</td></tr></tfoot>
                </table></div>
                <p class="guide-help">The funding factors intentionally overlap: holding the quote receives both the direct-funding points and the buy-side points. Holdings are self-reported, not balances fetched from your account.</p>
                <details><summary>How scoring becomes the final shortlist</summary>
                    <p>First, excluded assets, regional restrictions, inactive markets, missing price increments, goal conflicts and unfundable pairs are removed. The highest funding and goal scores enter the bounded history check.</p>
                    <p>Pairs exceeding your historical swing limit, having more than 10% zero-volume candles in usable history, or failing a comparable minimum-order check are removed. Missing evidence keeps a pair exploratory.</p>
                    <p>Fully screened matches come before exploratory matches, then higher scores come first; ties use the symbol alphabetically. Only one pair per base asset is retained, up to {{ max(1, min(5, (int) config('market_suggestions.shortlist_limit', 5))) }} results. This is not a ranking of expected profitability.</p>
                    <p>{{ $results['catalogue_count'] }} catalogue pairs; {{ $results['considered'] }} candidates received detailed checks.</p>
                </details>
            </section>

            <section class="guide-panel" aria-labelledby="history-heading">
                <div class="review-topline"><h2 id="history-heading">Closed-candle price history</h2><span class="guide-badge">{{ $evidence['period'] ?? 'Awaiting data' }}</span></div>
                <p>Price in {{ $item['quote'] }} per {{ $item['base'] }}. Times are UTC. Only completed candles are shown.</p>
                <div data-review-chart data-url="{{ $reviewUrl }}" data-evidence="{{ json_encode($evidence, JSON_THROW_ON_ERROR) }}" data-tick-size="{{ $market['tick_size'] }}" data-quote="{{ $item['quote'] }}" data-symbol="{{ $item['symbol'] }}">
                    <div class="guide-inline">
                        <button type="button" class="review-control" data-chart-refresh disabled>Refresh chart</button>
                        <button type="button" class="review-control" data-chart-fit disabled>Fit all candles</button>
                        <label class="guide-check"><input type="checkbox" data-chart-auto checked>Refresh every 60 seconds</label>
                    </div>
                    <p class="guide-help" role="status" aria-live="polite" data-chart-status>Loading chart…</p>
                    <p class="guide-notice" data-chart-evidence>{{ $evidence['message'] }}</p>
                    <p class="review-legend" data-chart-legend>Move over a candle to inspect its open, high, low, close and volume.</p>
                    <div class="review-chart" data-chart-canvas role="img" aria-label="{{ $exchange->name }} {{ $item['symbol'] }} closed candlesticks and volume" hidden></div>
                    <p class="guide-help" data-chart-freshness>
                        @if ($evidence['candles'])
                            {{ $evidence['candles'] }} closed candles · {{ $evidence['from'] }}–{{ $evidence['through'] }} UTC
                            @if ($evidence['stale']) · Stale history @endif
                        @else
                            No closed candles available yet. @if (! $item['subscribed']) Subscribe above to start collection. @else Collection may still be pending. @endif
                        @endif
                    </p>
                    <noscript><p>The interactive chart requires JavaScript. The historical evidence and candle table below remain available.</p></noscript>
                </div>
                <p class="guide-help">Updates read candles already collected by Trademinator, rather than streaming trades from the exchange. A refresh does not start collection. New data arrives through the existing scheduled collector.</p>
                <p class="guide-help">TradingView Lightweight Charts™ · Copyright (с) 2025 TradingView, Inc. · <a href="https://www.tradingview.com/" target="_blank" rel="noopener noreferrer">TradingView Lightweight Charts™</a>. TradingView is the chart library provider; the market data comes from Trademinator.</p>
                <details><summary>Recent candle values at page load</summary>
                    <div class="review-table-wrap"><table>
                        <caption class="guide-help">Latest {{ min(10, count($evidence['series'])) }} closed candles. OHLC prices in {{ $item['quote'] }}; volume as reported by the exchange.</caption>
                        <thead><tr><th scope="col">Open time (UTC)</th><th scope="col">Open</th><th scope="col">High</th><th scope="col">Low</th><th scope="col">Close</th><th scope="col">Volume</th></tr></thead>
                        <tbody>@forelse (array_reverse(array_slice($evidence['series'], -10)) as $candle)
                            <tr><th scope="row">{{ gmdate('Y-m-d H:i', $candle['time']) }}</th><td>{{ $number($candle['open']) }}</td><td>{{ $number($candle['high']) }}</td><td>{{ $number($candle['low']) }}</td><td>{{ $number($candle['close']) }}</td><td>{{ $number($candle['volume']) }}</td></tr>
                        @empty<tr><td colspan="6">No valid closed candles are stored for this sample.</td></tr>@endforelse</tbody>
                    </table></div>
                </details>
            </section>

            <section class="guide-panel" aria-labelledby="risk-heading">
                <h2 id="risk-heading">Historical risk screen</h2>
                <p>Screened at {{ $results['generated_at'] }}. Chart refreshes do not rewrite this explanation. <a data-no-instant href="{{ $reviewUrl }}">Recalculate the full review</a> to update it.</p>
                <div class="review-metrics">
                    <div class="review-metric">Largest peak-to-close decline<strong>{{ $evidence['known'] ? number_format($evidence['drawdown'] * 100, 2).'%' : 'Unknown' }}</strong><span class="guide-help">Across the whole sample</span></div>
                    <div class="review-metric">Largest holding-window move<strong>{{ $evidence['known'] ? number_format($evidence['largest_move'] * 100, 2).'%' : 'Unknown' }}</strong><span class="guide-help">Absolute change, either direction</span></div>
                    <div class="review-metric">Your historical swing threshold<strong>{{ number_format($item['risk_limit'] * 100, 1) }}%</strong><span class="guide-help">A screening limit, not a loss guarantee</span></div>
                </div>
                <p>{{ $evidence['message'] }} @if ($evidence['known']) Both price-swing measurements are at or below the threshold. {{ number_format($evidence['zero_volume_fraction'] * 100, 1) }}% of sampled candles have zero volume (maximum allowed: 10%). @endif</p>
                @if (! $evidence['known'])<p class="guide-notice">Unknown does not mean low risk. The chart can show a short, stale or incomplete sample even when it cannot support the risk screen.</p>@endif
                <details open><summary>Technical method and units</summary>
                    <p>Your “{{ $choices['horizon'][$answers['horizon']] }}” horizon maps to {{ $evidence['window_hours'] }} hours. The window is rounded up to whole candle intervals@if ($evidence['window_candles']): {{ $evidence['window_candles'] }} × {{ $evidence['period'] }} = {{ $number($evidence['effective_window_hours']) }} hours@endif.</p>
                    <p>The screen prefers stored 1d candles, otherwise the feed’s automatically selected period. It requires a fixed interval no longer than your horizon, at least {{ $evidence['required_candles'] }} continuous closed candles, and a recent last close. The stale cutoff is the greater of one hour or two candle intervals after the last candle closed.</p>
                    @if ($evidence['inverse'])
                        <p><strong>Inverse price measurement:</strong> your goal asset is {{ $item['base'] }}, so risk uses p(t) = 1 / close(t), measured in {{ $item['base'] }} per {{ $item['quote'] }}. The chart remains in the exchange’s normal {{ $item['quote'] }} per {{ $item['base'] }} quotation. The two directions can have different drawdowns.</p>
                    @else
                        <p>Risk uses p(t) = close(t), measured in {{ $item['quote'] }} per {{ $item['base'] }}.@if ($item['target'] !== $item['quote']) This is not a converted return in your goal currency, {{ $item['target'] }}.@endif</p>
                    @endif
                    <p class="review-formula">Peak(t) = max(p(0), …, p(t))<br>Drawdown = max(1 − p(t) / Peak(t))<br>Holding-window move = max(|p(t) / p(t − w) − 1|)<br>Pass price screen when max(Drawdown, Holding-window move) ≤ {{ $number($item['risk_limit']) }}</p>
                    <p class="guide-help">w is the holding-window length in candles. These are unannualized observations from this sample. Close-based measures omit intrabar extremes; zero-volume counts do not measure order-book depth. No KNN prediction or backtest profitability is used by this matcher.</p>
                </details>
            </section>

            <div class="guide-grid">
                <section class="guide-panel" aria-labelledby="cost-heading">
                    <h2 id="cost-heading">Costs and order constraints</h2>
                    <dl>
                        <dt>Price increment</dt><dd>{{ $market['tick_size'] }} {{ $item['quote'] }}</dd>
                        <dt>Published taker fee</dt><dd>{{ $market['taker_fee'] === null ? 'Unknown' : number_format($market['taker_fee'] * 100, 3).'% per side' }}</dd>
                        <dt>Two taker trades</dt><dd>{{ $market['taker_fee'] === null ? 'Unknown' : 'About '.number_format($market['taker_fee'] * 200, 3).'%' }} · excludes spread, slippage and conversions</dd>
                        <dt>Minimum quantity</dt><dd>{{ $number($market['min_amount']) }} {{ $item['base'] }}</dd>
                        <dt>Minimum order value</dt><dd>{{ $number($market['min_cost']) }} {{ $item['quote'] }}</dd>
                        <dt>Indicative minimum</dt><dd>{{ $number($market['indicative_min_cost']) }} {{ $item['quote'] }}</dd>
                        <dt>Allocation screen</dt><dd>{{ $market['affordability_checked'] ? 'Indicative minimum fits the upper bound of your stated range.' : 'Affordability has not been established.' }}</dd>
                    </dl>
                    <p class="guide-help">When quantity and a recent close are available, the indicative minimum is the larger of the published minimum value and minimum quantity × last close. Passing a range check does not establish your exact available balance.</p>
                    <p class="guide-help">Exchange catalogue metadata is cached for up to five minutes. Your account’s fee tier, spread and execution costs still need checking.</p>
                </section>
                <section class="guide-panel" aria-labelledby="preferences-heading">
                    <h2 id="preferences-heading">Your answers behind this match</h2>
                    <dl>
                        <dt>Residence</dt><dd>{{ $answers['country'] }}{{ $answers['region'] ? ' / '.$answers['region'] : '' }}</dd>
                        <dt>Goal</dt><dd>{{ $choices['goal'][$answers['goal']] }} · {{ $item['target'] }}</dd>
                        <dt>Holdings</dt><dd>@forelse ($answers['holdings'] as $holding)<div>{{ $holding['asset'] }} · {{ $bands[$holding['band']][0] }} {{ $answers['reference_currency'] }} approximate value</div>@empty Not provided @endforelse</dd>
                        <dt>Allocation</dt><dd>{{ $bands[$answers['allocation']][0] }} {{ $answers['reference_currency'] }}</dd>
                        <dt>Risk preference</dt><dd>{{ $choices['risk'][$answers['risk']] }}</dd>
                        <dt>Experience</dt><dd>{{ $choices['experience'][$answers['experience']] }}</dd>
                        <dt>Essential expenses</dt><dd>{{ $choices['loss_impact'][$answers['loss_impact']] }}</dd>
                        <dt>Money needed</dt><dd>{{ $choices['money_needed'][$answers['money_needed']] }}</dd>
                        <dt>Excluded assets</dt><dd>{{ $answers['excluded_assets'] ?: 'None specified' }}{{ $answers['exclude_stablecoins'] ? ' · Known stablecoins excluded' : '' }}</dd>
                        <dt>Monitoring</dt><dd>{{ $choices['monitoring'][$answers['monitoring']] }}</dd>
                        <dt>Conversions</dt><dd>{{ $choices['conversions'][$answers['conversions']] }}</dd>
                    </dl>
                    <p><a href="{{ route('markets.suggestions') }}">Edit your preferences</a></p>
                </section>
            </div>
            <section class="guide-panel" aria-labelledby="limitations-heading">
                <h2 id="limitations-heading">Access and remaining checks</h2>
                <p>{{ $results['access']['message'] }}</p>
                @if ($results['access']['source'])<p><a href="{{ $results['access']['source'] }}" target="_blank" rel="noopener noreferrer">Regional review source</a></p>@endif
                <ul>@foreach ($item['cautions'] as $caution)<li>{{ $caution }}</li>@endforeach</ul>
                <p class="guide-help">This review evaluates a market to follow. It does not establish that the pair will be profitable or that a trade should be placed now. You make the final choice.</p>
            </section>
        @endif
    </section>
</x-layouts.app>
