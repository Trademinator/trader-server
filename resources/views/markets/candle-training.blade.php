<x-layouts.app title="Candle Training">
    @include('markets.guide-styles')
    @include('markets.review-styles')
    <style>
        [data-candle-training-chart] [data-history-status],
        [data-candle-training-chart] [data-status],
        .pair-review > .guide-error[role="alert"] li { white-space:pre-wrap; overflow-wrap:anywhere; }
        .candle-training-buy { color:#087b6b; background:#ecfdf5; }
        .candle-training-hold { color:#475569; background:#f8fafc; }
        .candle-training-sell { color:#b4233b; background:#fff1f2; }
        .candle-training-navigation { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .candle-chart-navigation { display:grid; grid-template-columns:44px minmax(0,1fr) 44px; gap:8px; align-items:stretch; }
        .candle-chart-navigation .candle-step { display:flex; align-items:center; justify-content:center; padding:0; margin:14px 0; font-size:2rem; text-decoration:none; }
        .candle-step[aria-disabled="true"] { opacity:.4; cursor:default; pointer-events:none; }
        .candle-chart-stage { position:relative; min-width:0; }
        .candle-measure-tooltip { position:absolute; z-index:3; pointer-events:none; padding:6px 10px; border-radius:6px; background:#172c43; color:#fff; font-weight:700; white-space:nowrap; }
        .candle-training-measure [data-measure-move] { display:block; font-size:clamp(2rem,4vw,3rem); line-height:1.2; font-weight:750; font-variant-numeric:tabular-nums; }
        [data-measure-direction="up"] { color:#087b6b; }
        [data-measure-direction="down"] { color:#b4233b; }
        [data-measure-direction="flat"] { color:#64748b; }
        .dark [data-measure-direction="up"] { color:#9be7d8; }
        .dark [data-measure-direction="down"] { color:#ffb4c0; }
        .dark [data-measure-direction="flat"] { color:#cbd5e1; }
        .candle-menu button:disabled { opacity:.45; cursor:not-allowed; }
        .candle-training-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:14px 0; }
        .candle-training-stat { border:1px solid #cbd5e1; border-radius:8px; padding:10px; min-width:0; }
        .candle-training-stat strong { display:flex; justify-content:space-between; gap:8px; }
        .candle-training-stat progress { width:100%; height:10px; margin-top:8px; accent-color:var(--candle-milestone-color); }
        [data-milestone="red"] { --candle-milestone-color:#dc2626; }
        [data-milestone="orange"] { --candle-milestone-color:#f97316; }
        [data-milestone="green"] { --candle-milestone-color:#16a34a; }
        [data-milestone="blue"] { --candle-milestone-color:#2563eb; }
        .candle-training-stat progress::-webkit-progress-bar { background:#e2e8f0; border-radius:999px; }
        .candle-training-stat progress::-webkit-progress-value { background:var(--candle-milestone-color); border-radius:999px; }
        .candle-training-stat progress::-moz-progress-bar { background:var(--candle-milestone-color); border-radius:999px; }
        .candle-training-milestone-help { margin-top:8px; }
        .candle-training-milestone-help summary { cursor:pointer; font-weight:700; }
        .candle-training-milestone-list { display:grid; gap:6px; margin:10px 0; padding-left:0; list-style:none; }
        .candle-training-milestone-swatch { display:inline-block; width:.8rem; height:.8rem; margin-right:6px; border-radius:999px; background:var(--candle-milestone-color); vertical-align:-.05rem; }
        .candle-training-measure { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin:12px 0; }
        .candle-training-measure > div { border:1px solid #cbd5e1; border-radius:8px; padding:10px; min-width:0; }
        .candle-training-measure .measure-wide { grid-column:1 / -1; }
        .candle-menu { position:fixed; z-index:1000; min-width:210px; padding:10px; border:1px solid #94a3b8; border-radius:10px; background:#fff; box-shadow:0 10px 30px rgba(15,23,42,.18); }
        .candle-menu[hidden] { display:none; }
        .candle-menu strong { display:block; margin-bottom:8px; }
        .candle-menu-actions { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; }
        .candle-menu button { min-height:42px; border:1px solid #94a3b8; border-radius:7px; cursor:pointer; font-weight:700; }
        .candle-menu-delete { width:100%; margin-top:8px; background:#fff1f2; color:#b4233b; }
        .pair-guide .candle-training-fee-note { color:#163e70; }
        .pair-guide .candle-training-fee-note strong { color:#0f2f57; }
        .pair-guide .candle-training-fee-note .candle-training-fee-detail { color:#243b53; }
        .dark .pair-guide .candle-training-fee-note { color:#163e70; }
        .dark .pair-guide .candle-training-fee-note strong { color:#0f2f57; }
        .dark .pair-guide .candle-training-fee-note .candle-training-fee-detail { color:#243b53; }
        @media (max-width: 620px) {
            .candle-training-stats, .candle-training-measure { grid-template-columns:1fr; }
            .candle-training-measure .measure-wide { grid-column:auto; }
        }
        .dark .candle-training-buy { background:#0d332d; color:#9be7d8; }
        .dark .candle-training-hold { background:#1f2937; color:#d7e0ea; }
        .dark .candle-training-sell { background:#3d1820; color:#ffb4c0; }
        .dark .candle-training-stat, .dark .candle-training-measure > div { border-color:#475569; }
        .dark .candle-training-stat progress::-webkit-progress-bar { background:#334155; }
        .dark .candle-menu { background:#111827; border-color:#64748b; color:#e5edf5; box-shadow:0 10px 30px rgba(0,0,0,.45); }
    </style>
    <section class="pair-guide pair-review">
        <nav><a href="{{ route('human-training.index') }}">← Human training</a></nav>
        <header class="guide-hero">
            <p class="review-eyebrow">Candle Training · {{ $state['payload']['exchange'] }} · {{ $state['payload']['symbol'] }} · {{ $state['payload']['period'] }}</p>
            <h1>Candle Training</h1>
            <p>Choose a pair, explore its history and label candles directly on the chart. Pan through the chart to load older or newer candles within the selected dataset.</p>
        </header>
        @if(session('status'))<p class="guide-notice" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="guide-notice guide-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        @if($state['review_required'] ?? false)
            <p class="guide-notice" role="status">This candle has a replacement snapshot after its inputs changed. Your earlier label remains attached to the old snapshot; review this version before submitting a new label.</p>
        @endif
        <p class="guide-help">Trend Training and Candle Training are optional enhancements. No manual training quota is required for the ordinary model.</p>
        <section class="guide-panel" data-candle-training-chart
                 data-replay-url="{{ route('human-training.candles.show', $state['manifest']['dataset_id']) }}"
                 data-history-url="{{ route('human-training.candles.history', $state['manifest']['dataset_id']) }}"
                 data-update-url="{{ route('human-training.candles.update', $state['manifest']['dataset_id']) }}"
                 data-delete-url="{{ route('human-training.candles.destroy', $state['manifest']['dataset_id']) }}"
                 data-auto-url="{{ route('human-training.candles.auto-label', $state['manifest']['dataset_id']) }}"
                 data-submit-url="{{ route('human-training.candles.submit', $state['manifest']['dataset_id']) }}"
                 data-submit-batch-size="{{ min(\App\Domain\Intelligence\CandleTraining::SUBMIT_BATCH_SIZE, \App\Domain\Intelligence\CandleTraining::submissionLimit()) }}"
                 data-csrf="{{ csrf_token() }}"
                 data-snapshot="{{ json_encode(['series' => $state['payload']['series'], 'decision_at_ms' => $state['payload']['decision_at_ms'], 'labels' => $state['visible_labels'], 'decisions' => $state['decisions'], 'allowed_actions' => $state['allowed_actions'], 'has_more' => $state['has_more'], 'has_newer' => $state['next_decision_at_ms'] !== null, 'latest_decision_at_ms' => $state['latest_decision_at_ms'], 'stats' => $state['label_stats'], 'taker_fee' => $state['taker_fee']], JSON_THROW_ON_ERROR) }}">
            <form method="POST" action="{{ route('human-training.candles.start') }}" data-candle-dataset-form>
                @csrf
                <label for="candle-dataset">Market and frozen dataset</label>
                <x-subscribed-pair-select name="dataset" id="candle-dataset" :value="$state['manifest']['dataset_id']"
                    :datasets="$datasets" :current-dataset="$state['manifest']" :all-subscribed="true"
                    :sort="['exchange', 'pair', 'period']" :show-unavailable-datasets="true" required data-candle-dataset />
                <noscript><button class="review-control" type="submit">Switch market</button></noscript>
            </form>
            <p class="guide-help">Latest loaded candle: <span data-replay-time><x-display-time :value="$state['payload']['microtimestamp']" unit="milliseconds" /></span>. Use the side arrows to move up to 50 candles, or pan toward either edge to load more history. Forward loading stops at the newest candle in this dataset.</p>
            <p class="guide-notice"><strong>Left-click</strong> candles to measure A→B. The third click discards A and shifts B→A. <strong>Right-click</strong> a candle for BUY/HOLD/SELL/Delete; on touch, long-press it.</p>
            @if($state['payload']['gaps'])<p class="guide-notice guide-error">{{ $state['payload']['gaps'] }} gaps in history. Missing candles are not filled.</p>@endif

            <h2>Your recorded label milestones for this market and period</h2>
            <div class="candle-training-stats" aria-label="Candle Training label milestone counts">
                @foreach(['buy' => 'BUY', 'hold' => 'HOLD', 'sell' => 'SELL'] as $action => $title)
                    @php
                        $count = $state['label_stats']['counts'][$action];
                        $milestone = $count < 100 ? 'red' : ($count < 300 ? 'orange' : ($count < 750 ? 'green' : 'blue'));
                    @endphp
                    <div class="candle-training-stat">
                        <strong><span>{{ $title }}</span><span data-stat-count="{{ $action }}">{{ $count }}</span></strong>
                        <progress data-stat-progress="{{ $action }}" data-milestone="{{ $milestone }}"
                            value="{{ min($count, 750) }}" max="750"
                            aria-label="{{ $title }} label milestone progress: {{ $count }} labels"></progress>
                    </div>
                @endforeach
            </div>
            <p class="guide-help">Saved labels: <strong data-stat-total>{{ $state['label_stats']['total'] }}</strong>. Milestones count each submitted candle once across revisions and update after a successful Submit. Pending changes are shown below. The model report shows compatible training counts. There is no target BUY/HOLD/SELL percentage.</p>
            <details class="candle-training-milestone-help">
                <summary>What do the milestone colours mean?</summary>
                <ul class="candle-training-milestone-list">
                    <li><span class="candle-training-milestone-swatch" data-milestone="red" aria-hidden="true"></span><strong>Red:</strong> fewer than 100 labels — still a small evidence set.</li>
                    <li><span class="candle-training-milestone-swatch" data-milestone="orange" aria-hidden="true"></span><strong>Orange:</strong> 100–299 labels — early evidence; keep adding varied examples.</li>
                    <li><span class="candle-training-milestone-swatch" data-milestone="green" aria-hidden="true"></span><strong>Green:</strong> 300–749 labels — useful volume, but broader market conditions still help.</li>
                    <li><span class="candle-training-milestone-swatch" data-milestone="blue" aria-hidden="true"></span><strong>Blue:</strong> 750+ labels — strong evidence volume for that action.</li>
                </ul>
                <p class="guide-help"><strong>Do not try to make the three bars equal.</strong> Real decisions may naturally contain many more HOLD labels. Record the action you would genuinely take; artificially balancing the labels would make the human signal less representative. The auxiliary model retains every eligible distinct labelled candle. It compares natural class frequencies against 25% BUY / 50% HOLD / 25% SELL vote weights during chronological tuning; those are not label quotas.</p>
                <p class="guide-help">These colours measure quantity only. Diversity across market regimes, volatility and historical periods still matters.</p>
            </details>

            <div>
                <div class="candle-training-measure" aria-live="polite">
                    <div><strong>A</strong><br><span data-measure-a>Click a candle</span></div>
                    <div><strong>B</strong><br><span data-measure-b>Click a second candle</span></div>
                    <div><strong>Price move</strong><span data-measure-move data-measure-direction="flat">—</span><small>close-to-close · A → B</small></div>
                    <div><strong>Fee comparison</strong><br><span data-measure-fee>{{ $state['taker_fee'] === null ? 'Published exchange taker fee unavailable.' : 'Published taker fee '.\App\Helpers\Decimal::format($state['taker_fee'] * 100, 3).'% per side.' }}</span></div>
                </div>
                <div class="guide-notice candle-training-fee-note" data-current-exchange-fees>
                    @if($state['taker_fee'] !== null)
                        <strong>Current published exchange fee:</strong>
                        taker {{ \App\Helpers\Decimal::format($state['taker_fee'] * 100, 4) }}% per side ·
                        approximately {{ \App\Helpers\Decimal::format($state['taker_fee'] * 200, 4) }}% round trip.
                        <span class="candle-training-fee-detail">Auto-label profitability filtering uses the round-trip taker fee as its economic floor. Spread, slippage, conversions and account-specific fee discounts are not included.</span>
                    @else
                        <strong>Current published exchange fee:</strong> unavailable.
                        <span class="candle-training-fee-detail">Auto-label cannot apply its transaction-cost profitability floor until the exchange exposes a taker fee.</span>
                    @endif
                </div>
                <p class="review-legend" data-legend>Move over a candle to inspect OHLC and volume. Existing BUY/HOLD/SELL labels remain marked on the chart.</p>
                <div class="candle-chart-navigation" aria-label="Replay navigation">
                    <a class="review-control candle-step" data-step-previous
                        @if($state['previous_decision_at_ms']) href="{{ route('human-training.candles.show', ['dataset' => $state['manifest']['dataset_id'], 'decision_at_ms' => $state['previous_decision_at_ms']]) }}" @else aria-disabled="true" tabindex="-1" @endif
                        aria-label="Back up to 50 candles" title="Back up to 50 candles">&lt;</a>
                    <x-market-candlestick class="candle-chart-stage"
                        canvas-class="review-chart"
                        aria-label="Historical candlesticks with human training markers and A/B measurement selections"
                        :show-status="false" :show-legend="false">
                        <div class="candle-measure-tooltip" data-measure-tooltip hidden role="tooltip"></div>
                    </x-market-candlestick>
                    <a class="review-control candle-step" data-step-next
                        @if($state['next_decision_at_ms']) href="{{ route('human-training.candles.show', ['dataset' => $state['manifest']['dataset_id'], 'decision_at_ms' => $state['next_decision_at_ms']]) }}" @else aria-disabled="true" tabindex="-1" @endif
                        aria-label="Forward up to 50 candles" title="Forward up to 50 candles">&gt;</a>
                </div>
                <p class="guide-help">Earliest available data: <a href="{{ route('human-training.candles.show', ['dataset' => $state['manifest']['dataset_id'], 'decision_at_ms' => $state['earliest_window_decision_at_ms']]) }}"><x-display-time :value="$state['earliest_time']" unit="seconds" /></a> · in this frozen dataset</p>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="review-control" data-fit>Fit visible candles</button>
                    <button type="button" class="review-control" data-auto-label>Auto-label</button>
                    <button type="button" class="review-control" data-submit-labels hidden>Submit</button>
                    <button type="button" class="review-control candle-menu-delete" data-delete-all-training>Delete all training</button>
                </div>
                <p class="guide-help" data-pending-status>No pending changes. Manual labels, deletions and auto-label suggestions stay only in this browser until Submit.</p>
                <p class="guide-help" data-history-status role="status"></p>
                <button type="button" class="review-control" data-history-retry hidden>Retry loading candles</button>
                <p class="guide-help" data-status role="status">Loading the Candle Training chart…</p>
                <div class="candle-menu" data-candle-menu hidden role="menu" aria-label="Candle action menu">
                    <strong data-menu-title>Selected candle</strong>
                    <div class="candle-menu-actions">
                        <button type="button" class="candle-training-buy" data-menu-action="buy" role="menuitem">▲ BUY</button>
                        <button type="button" class="candle-training-hold" data-menu-action="hold" role="menuitem">● HOLD</button>
                        <button type="button" class="candle-training-sell" data-menu-action="sell" role="menuitem">▼ SELL</button>
                    </div>
                    <p class="guide-help" data-menu-note>BUY on red bars; SELL on green bars. HOLD on any bar.</p>
                    <button type="button" class="candle-menu-delete" data-menu-action="delete" role="menuitem">Delete my label</button>
                </div>
                <noscript><p>The interactive chart requires JavaScript. Use JavaScript to display the chart and label candles.</p></noscript>
            </div>
            <p class="guide-help">Green ▲ = BUY, gray ● = HOLD, red ▼ = SELL. Purple/orange squares are temporary A/B measurement markers. Manual selections and Auto-label suggestions are the same Candle Training labels: neither reaches the database until you press Submit. Human labels are training annotations, not exchange orders or historical fills. The fee comparison uses the published CCXT taker fee when available and excludes spread, slippage, conversions and account-specific discounts. TradingView Lightweight Charts™ · <a href="https://www.tradingview.com/" target="_blank" rel="noopener noreferrer">TradingView</a>.</p>
        </section>

        <section class="guide-panel">
            <p class="guide-help"><strong>Unlabelled does not mean HOLD.</strong> Unlabelled means you supplied no human opinion for this candle, so it is excluded from Candle Training. HOLD is an explicit action label and becomes supervised training data. Deleting a label removes it from future builds; an already-published model is immutable until intelligence is rebuilt.</p>
        </section>

        <div class="guide-grid">
            <section class="guide-panel"><h2>Indicators and context</h2><p class="guide-help">Features for the initial candle at <x-display-time :value="$state['payload']['microtimestamp']" unit="milliseconds" />. Each chart label stores the features from the candle you label.</p>
                <dl>@foreach($state['payload']['features'] as $key => $value)<dt>{{ $key }}</dt><dd>{{ is_numeric($value) ? \App\Helpers\Decimal::format($value, 5) : 'Unavailable' }}</dd>@endforeach</dl>
            </section>
            <section class="guide-panel"><h2>Partial patterns</h2>
                @forelse($state['payload']['patterns'] as $pattern)<p><strong>{{ ucwords(str_replace('_', ' ', $pattern['type'])) }}</strong><br>Stage {{ $pattern['stage'] }}/{{ $pattern['length'] }} · {{ \App\Helpers\Decimal::format(100 * $pattern['progress']) }}% complete · similarity {{ \App\Helpers\Decimal::format(100 * $pattern['similarity']) }}%</p>
                @empty<p>No supported partial pattern in this snapshot.</p>@endforelse
                <p class="guide-help">Patterns for the initial candle at <x-display-time :value="$state['payload']['microtimestamp']" unit="milliseconds" />. Pattern outcomes stay hidden.</p>
            </section>
        </div>

        <section class="guide-panel">
            <details><summary>Initial window: recent candle values</summary><div class="review-table-wrap"><table><thead><tr><th>Open time (<x-timezone-label />)</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Volume</th></tr></thead><tbody>
                @foreach(array_slice($state['payload']['series'], -10) as $candle)<tr><th><x-display-time :value="$candle['time']" unit="seconds" precision="minutes" /></th>@foreach(['open', 'high', 'low', 'close', 'volume'] as $field)<td>{{ $candle[$field] }}</td>@endforeach</tr>@endforeach
            </tbody></table></div></details>
        </section>
    </section>
</x-layouts.app>
