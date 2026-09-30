<x-layouts.app title="Candle Training">
    @include('markets.guide-styles')
    @include('markets.review-styles')
    <style>
        .candle-training-actions { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:14px 0; }
        .candle-training-actions button { min-height:48px; border:1px solid #94a3b8; border-radius:8px; font-weight:700; cursor:pointer; }
        .candle-training-actions button[aria-pressed="true"] { outline:3px solid currentColor; outline-offset:2px; }
        .candle-training-buy { color:#087b6b; background:#ecfdf5; }
        .candle-training-hold { color:#475569; background:#f8fafc; }
        .candle-training-sell { color:#b4233b; background:#fff1f2; }
        .candle-training-navigation { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .candle-training-navigation .review-control[aria-disabled="true"] { opacity:.5; pointer-events:none; }
        .candle-training-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:14px 0; }
        .candle-training-stat { border:1px solid #cbd5e1; border-radius:8px; padding:10px; min-width:0; }
        .candle-training-stat strong { display:flex; justify-content:space-between; gap:8px; }
        .candle-training-stat progress { width:100%; height:10px; margin-top:8px; }
        .candle-training-measure { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin:12px 0; }
        .candle-training-measure > div { border:1px solid #cbd5e1; border-radius:8px; padding:10px; min-width:0; }
        .candle-training-measure .measure-wide { grid-column:1 / -1; }
        .candle-menu { position:fixed; z-index:1000; min-width:210px; padding:10px; border:1px solid #94a3b8; border-radius:10px; background:#fff; box-shadow:0 10px 30px rgba(15,23,42,.18); }
        .candle-menu[hidden] { display:none; }
        .candle-menu strong { display:block; margin-bottom:8px; }
        .candle-menu-actions { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; }
        .candle-menu button { min-height:42px; border:1px solid #94a3b8; border-radius:7px; cursor:pointer; font-weight:700; }
        .candle-menu-delete { width:100%; margin-top:8px; background:#fff1f2; color:#b4233b; }
        @media (max-width: 620px) {
            .candle-training-actions, .candle-training-stats, .candle-training-measure { grid-template-columns:1fr; }
            .candle-training-measure .measure-wide { grid-column:auto; }
        }
        .dark .candle-training-buy { background:#0d332d; color:#9be7d8; }
        .dark .candle-training-hold { background:#1f2937; color:#d7e0ea; }
        .dark .candle-training-sell { background:#3d1820; color:#ffb4c0; }
        .dark .candle-training-stat, .dark .candle-training-measure > div { border-color:#475569; }
        .dark .candle-menu { background:#111827; border-color:#64748b; color:#e5edf5; box-shadow:0 10px 30px rgba(0,0,0,.45); }
    </style>
    <section class="pair-guide pair-review">
        <nav><a href="{{ route('human-training.index') }}">← Human training</a></nav>
        <header class="guide-hero">
            <p class="review-eyebrow">Candle Training · {{ $state['payload']['exchange'] }} · {{ $state['payload']['symbol'] }} · {{ $state['payload']['period'] }}</p>
            <h1>Candle Training</h1>
            <p>Label exact candles with the action you would have taken. The replay cursor still hides later history, while chart shortcuts let you measure candles and label visible bars without scrolling to the form.</p>
        </header>
        @if(session('status'))<p class="guide-notice" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="guide-notice guide-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <section class="guide-panel">
            <div class="candle-training-navigation" aria-label="Replay navigation">
                @if($state['previous_decision_at_ms'])
                    <a class="review-control" href="{{ route('human-training.candles.show', ['dataset' => $state['manifest']['dataset_id'], 'decision_at_ms' => $state['previous_decision_at_ms']]) }}">← Previous candle</a>
                @else
                    <span class="review-control" aria-disabled="true">← Previous candle</span>
                @endif
                @if($state['next_decision_at_ms'])
                    <a class="review-control" href="{{ route('human-training.candles.show', ['dataset' => $state['manifest']['dataset_id'], 'decision_at_ms' => $state['next_decision_at_ms']]) }}">Next candle →</a>
                @else
                    <span class="review-control" aria-disabled="true">Next candle →</span>
                @endif
                <span class="guide-help">Replay candle: {{ gmdate('Y-m-d H:i:s', intdiv($state['payload']['microtimestamp'], 1000)) }} UTC</span>
            </div>
            <p class="guide-notice"><strong>Left-click</strong> candles to measure A→B. The third click discards A and shifts B→A. <strong>Right-click</strong> a candle for BUY/HOLD/SELL/Delete; on touch, long-press it.</p>
            @if($state['payload']['gaps'])<p class="guide-notice guide-error">{{ $state['payload']['gaps'] }} gaps in history. Missing candles are not filled.</p>@endif

            <h2>Your label balance for this market and period</h2>
            <div class="candle-training-stats" aria-label="Candle Training label distribution">
                @foreach(['buy' => 'BUY', 'hold' => 'HOLD', 'sell' => 'SELL'] as $action => $title)
                    <div class="candle-training-stat">
                        <strong><span>{{ $title }}</span><span><span data-stat-count="{{ $action }}">{{ $state['label_stats']['counts'][$action] }}</span> · <span data-stat-percent="{{ $action }}">{{ number_format($state['label_stats']['percentages'][$action], 1) }}%</span></span></strong>
                        <progress data-stat-progress="{{ $action }}" value="{{ $state['label_stats']['counts'][$action] }}" max="{{ max(1, $state['label_stats']['total']) }}"></progress>
                    </div>
                @endforeach
            </div>
            <p class="guide-help">Total labels: <strong data-stat-total>{{ $state['label_stats']['total'] }}</strong>. Equal-class raw subset: <strong data-balanced-samples>{{ $state['label_stats']['balanced_samples'] }}</strong>. <span data-balance-hint>@if($state['label_stats']['total']) Least represented: {{ strtoupper(implode(', ', $state['label_stats']['least_represented'])) }}. @else No labels yet. @endif Model training uses equal counts from BUY/HOLD/SELL; do not force a label just to balance the totals.</span></p>

            <div data-candle-training-chart
                 data-update-url="{{ route('human-training.candles.update', $state['manifest']['dataset_id']) }}"
                 data-delete-url="{{ route('human-training.candles.destroy', $state['manifest']['dataset_id']) }}"
                 data-csrf="{{ csrf_token() }}"
                 data-snapshot="{{ json_encode(['series' => $state['payload']['series'], 'decision_at_ms' => $state['payload']['decision_at_ms'], 'labels' => $state['visible_labels'], 'decisions' => $state['decisions'], 'selected_action' => $state['label']?->action, 'stats' => $state['label_stats'], 'taker_fee' => $state['taker_fee']], JSON_THROW_ON_ERROR) }}">
                <div class="candle-training-measure" aria-live="polite">
                    <div><strong>A</strong><br><span data-measure-a>Click a candle</span></div>
                    <div><strong>B</strong><br><span data-measure-b>Click a second candle</span></div>
                    <div><strong>Price move</strong><br><span data-measure-move>—</span></div>
                    <div><strong>Fee comparison</strong><br><span data-measure-fee>{{ $state['taker_fee'] === null ? 'Published exchange taker fee unavailable.' : 'Published taker fee '.number_format($state['taker_fee'] * 100, 3).'% per side.' }}</span></div>
                </div>
                <p class="review-legend" data-legend>Move over a candle to inspect OHLC and volume. Existing BUY/HOLD/SELL labels remain marked on the chart.</p>
                <div class="review-chart" data-canvas role="img" aria-label="Historical candlesticks with human training markers and A/B measurement selections"></div>
                <button type="button" class="review-control" data-fit>Fit visible candles</button>
                <p class="guide-help" data-status role="status">Loading the future-hidden Candle Training chart…</p>
                <div class="candle-menu" data-candle-menu hidden role="menu" aria-label="Candle action menu">
                    <strong data-menu-title>Selected candle</strong>
                    <div class="candle-menu-actions">
                        <button type="button" class="candle-training-buy" data-menu-action="buy" role="menuitem">▲ BUY</button>
                        <button type="button" class="candle-training-hold" data-menu-action="hold" role="menuitem">● HOLD</button>
                        <button type="button" class="candle-training-sell" data-menu-action="sell" role="menuitem">▼ SELL</button>
                    </div>
                    <button type="button" class="candle-menu-delete" data-menu-action="delete" role="menuitem">Delete my label</button>
                </div>
                <noscript><p>The interactive chart requires JavaScript. Replay navigation and action buttons remain available.</p></noscript>
            </div>
            <p class="guide-help">Green ▲ = BUY, gray ● = HOLD, red ▼ = SELL. Purple/orange squares are temporary A/B measurement markers. Human labels are training annotations, not exchange orders or historical fills. The fee comparison uses the published CCXT taker fee when available and excludes spread, slippage, conversions and account-specific discounts. TradingView Lightweight Charts™ · <a href="https://www.tradingview.com/" target="_blank" rel="noopener noreferrer">TradingView</a>.</p>
        </section>

        <section class="guide-panel">
            <h2>Your action on the replay candle</h2>
            <p data-current-label-copy>{{ $state['label'] ? 'Current label: '.strtoupper($state['label']->action).'. Choose another action to change it.' : 'This candle is currently unlabelled.' }}</p>
            <form method="POST" action="{{ route('human-training.candles.update', $state['manifest']['dataset_id']) }}">
                @csrf @method('PUT')
                <input type="hidden" name="decision_at_ms" value="{{ $state['payload']['decision_at_ms'] }}">
                <div class="candle-training-actions">
                    <button type="submit" name="action" value="buy" data-current-action class="candle-training-buy" aria-pressed="{{ $state['label']?->action === 'buy' ? 'true' : 'false' }}">▲ BUY</button>
                    <button type="submit" name="action" value="hold" data-current-action class="candle-training-hold" aria-pressed="{{ $state['label']?->action === 'hold' ? 'true' : 'false' }}">● HOLD</button>
                    <button type="submit" name="action" value="sell" data-current-action class="candle-training-sell" aria-pressed="{{ $state['label']?->action === 'sell' ? 'true' : 'false' }}">▼ SELL</button>
                </div>
            </form>
            @if($state['label'])
                <form method="POST" action="{{ route('human-training.candles.destroy', $state['manifest']['dataset_id']) }}">
                    @csrf @method('DELETE')
                    <input type="hidden" name="decision_at_ms" value="{{ $state['payload']['decision_at_ms'] }}">
                    <button type="submit" class="review-control">Remove label</button>
                </form>
            @endif
            <p class="guide-help"><strong>Unlabelled does not mean HOLD.</strong> Unlabelled means you supplied no human opinion for this candle, so it is excluded from Candle Training. HOLD is an explicit action label and becomes supervised training data. Deleting a label removes it from future builds; an already-published model is immutable until intelligence is rebuilt.</p>
        </section>

        <div class="guide-grid">
            <section class="guide-panel"><h2>Indicators and context</h2><p class="guide-help">Frozen features from this exact candle. These are the inputs stored with your BUY/HOLD/SELL label.</p>
                <dl>@foreach($state['payload']['features'] as $key => $value)<dt>{{ $key }}</dt><dd>{{ is_numeric($value) ? number_format($value, 5) : 'Unavailable' }}</dd>@endforeach</dl>
            </section>
            <section class="guide-panel"><h2>Partial patterns</h2>
                @forelse($state['payload']['patterns'] as $pattern)<p><strong>{{ ucwords(str_replace('_', ' ', $pattern['type'])) }}</strong><br>Stage {{ $pattern['stage'] }}/{{ $pattern['length'] }} · {{ number_format(100 * $pattern['progress']) }}% complete · similarity {{ number_format(100 * $pattern['similarity']) }}%</p>
                @empty<p>No supported partial pattern in this snapshot.</p>@endforelse
                <p class="guide-help">Pattern outcomes and later prices stay hidden while you label the candle.</p>
            </section>
        </div>

        <section class="guide-panel">
            <details><summary>Recent visible candle values</summary><div class="review-table-wrap"><table><thead><tr><th>Open time (UTC)</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Volume</th></tr></thead><tbody>
                @foreach(array_slice($state['payload']['series'], -10) as $candle)<tr><th>{{ gmdate('Y-m-d H:i', $candle['time']) }}</th>@foreach(['open', 'high', 'low', 'close', 'volume'] as $field)<td>{{ $candle[$field] }}</td>@endforeach</tr>@endforeach
            </tbody></table></div></details>
        </section>
    </section>
</x-layouts.app>
