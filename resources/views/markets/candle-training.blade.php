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
        @media (max-width: 520px) { .candle-training-actions { grid-template-columns:1fr; } }
        .dark .candle-training-buy { background:#0d332d; color:#9be7d8; }
        .dark .candle-training-hold { background:#1f2937; color:#d7e0ea; }
        .dark .candle-training-sell { background:#3d1820; color:#ffb4c0; }
    </style>
    <section class="pair-guide pair-review">
        <nav><a href="{{ route('human-training.index') }}">← Human training</a></nav>
        <header class="guide-hero">
            <p class="review-eyebrow">Candle Training · {{ $state['payload']['exchange'] }} · {{ $state['payload']['symbol'] }} · {{ $state['payload']['period'] }}</p>
            <h1>Candle Training</h1>
            <p>Choose the action you would have taken on the selected closed candle. Later candles stay hidden so the label is based only on information available at that time.</p>
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
                <span class="guide-help">Selected candle: {{ gmdate('Y-m-d H:i:s', intdiv($state['payload']['microtimestamp'], 1000)) }} UTC</span>
            </div>
            <p class="guide-notice">Future candles are hidden. Click an earlier candle in the chart to move the replay cursor backward. Use Next candle to reveal history one candle at a time.</p>
            @if($state['payload']['gaps'])<p class="guide-notice guide-error">{{ $state['payload']['gaps'] }} gaps in history. Missing candles are not filled.</p>@endif
            <div data-candle-training-chart
                 data-select-url="{{ route('human-training.candles.show', $state['manifest']['dataset_id']) }}"
                 data-snapshot="{{ json_encode(['series' => $state['payload']['series'], 'decision_at_ms' => $state['payload']['decision_at_ms'], 'labels' => $state['visible_labels'], 'decisions' => $state['decisions'], 'selected_action' => $state['label']?->action], JSON_THROW_ON_ERROR) }}">
                <p class="review-legend" data-legend>Click a visible candle to select it. Move over a candle to inspect OHLC and volume.</p>
                <div class="review-chart" data-canvas role="img" aria-label="Historical candlesticks ending at the Candle Training decision time"></div>
                <button type="button" class="review-control" data-fit>Fit visible candles</button>
                <p class="guide-help" data-status role="status">Loading the future-hidden Candle Training chart…</p>
                <noscript><p>The interactive chart requires JavaScript. Replay navigation and action buttons remain available.</p></noscript>
            </div>
            <p class="guide-help">Green ▲ = BUY, gray ● = HOLD, red ▼ = SELL. These are your training annotations, not exchange orders or historical fills. TradingView Lightweight Charts™ · <a href="https://www.tradingview.com/" target="_blank" rel="noopener noreferrer">TradingView</a>.</p>
        </section>

        <section class="guide-panel">
            <h2>Your action on this candle</h2>
            <p>{{ $state['label'] ? 'Current label: '.strtoupper($state['label']->action).'. Choose another action to change it.' : 'This candle is currently unlabelled.' }}</p>
            <form method="POST" action="{{ route('human-training.candles.update', $state['manifest']['dataset_id']) }}">
                @csrf @method('PUT')
                <input type="hidden" name="decision_at_ms" value="{{ $state['payload']['decision_at_ms'] }}">
                <div class="candle-training-actions">
                    <button type="submit" name="action" value="buy" class="candle-training-buy" aria-pressed="{{ $state['label']?->action === 'buy' ? 'true' : 'false' }}">▲ BUY</button>
                    <button type="submit" name="action" value="hold" class="candle-training-hold" aria-pressed="{{ $state['label']?->action === 'hold' ? 'true' : 'false' }}">● HOLD</button>
                    <button type="submit" name="action" value="sell" class="candle-training-sell" aria-pressed="{{ $state['label']?->action === 'sell' ? 'true' : 'false' }}">▼ SELL</button>
                </div>
            </form>
            @if($state['label'])
                <form method="POST" action="{{ route('human-training.candles.destroy', $state['manifest']['dataset_id']) }}">
                    @csrf @method('DELETE')
                    <input type="hidden" name="decision_at_ms" value="{{ $state['payload']['decision_at_ms'] }}">
                    <button type="submit" class="review-control">Remove label</button>
                </form>
            @endif
            <p class="guide-help"><strong>Unlabelled does not mean HOLD.</strong> Unlabelled means you supplied no human opinion for this candle, so it is excluded from Candle Training. HOLD is an explicit action label and becomes supervised training data.</p>
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
