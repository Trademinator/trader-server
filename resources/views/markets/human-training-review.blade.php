<x-layouts.app title="Trend Training snapshot">
    @include('markets.guide-styles')
    @include('markets.review-styles')
    <style>
        .human-labels { display:grid; grid-template-columns:repeat(auto-fit,minmax(135px,1fr)); gap:12px; margin:14px 0; }
        .human-labels label { display:flex; align-items:center; gap:8px; padding:12px; border:1px solid #94a3b8; border-radius:8px; cursor:pointer; }
        .pair-guide .human-labels input[type=radio] { width:20px; height:20px; min-height:20px; margin:0; padding:0; }
        .pair-guide textarea { display:block; width:100%; padding:10px; border:2px solid #94a3b8; border-radius:8px; background:white; color:#172033; }
    </style>
    <section class="pair-guide pair-review">
        <nav><a href="{{ route('human-training.index') }}">← Human training</a></nav>
        <header class="guide-hero"><p class="review-eyebrow">Trend Training · {{ $snapshot['exchange'] }} · {{ $snapshot['symbol'] }} · {{ $snapshot['period'] }}</p>
            <h1>Trend Training</h1><p>Assess the expected market trend over the next {{ $snapshot['horizon_candles'] }} candles from {{ gmdate('Y-m-d H:i:s', intdiv($snapshot['decision_at_ms'], 1000)) }} UTC.</p></header>
        @if(session('status'))<p class="guide-notice" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="guide-notice guide-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <section class="guide-panel"><h2>Closed candles at the decision time</h2>
            <p class="guide-notice">Future candles are hidden. This chart stays frozen and does not refresh.</p>
            @if($snapshot['gaps'])<p class="guide-notice guide-error">{{ $snapshot['gaps'] }} gaps in history. Missing candles are not filled.</p>@endif
            <div data-human-training-chart data-snapshot="{{ json_encode(['series' => $snapshot['series'], 'decision_at_ms' => $snapshot['decision_at_ms'], 'label' => $review->label], JSON_THROW_ON_ERROR) }}">
                <p class="review-legend" data-legend>Inspect a candle for its OHLC prices and volume.</p>
                <div class="review-chart" data-canvas role="img" aria-label="Historical candlesticks ending at the Trend Training decision time"></div>
                <button type="button" class="review-control" data-fit>Fit all candles</button>
                <p class="guide-help" data-status role="status">Loading the frozen chart…</p>
                <noscript><p>The interactive chart requires JavaScript. Candle values and indicators remain available below.</p></noscript>
            </div>
            <p class="guide-help">A saved green or red arrow represents your Bull or Bear trend assessment, not a trade. Hold is gray. TradingView Lightweight Charts™ · <a href="https://www.tradingview.com/" target="_blank" rel="noopener noreferrer">TradingView</a>.</p>
            <details><summary>Recent candle values</summary><div class="review-table-wrap"><table><thead><tr><th>Open time (UTC)</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Volume</th></tr></thead><tbody>
                @foreach(array_slice($snapshot['series'], -10) as $candle)<tr><th>{{ gmdate('Y-m-d H:i', $candle['time']) }}</th>@foreach(['open', 'high', 'low', 'close', 'volume'] as $field)<td>{{ $candle[$field] }}</td>@endforeach</tr>@endforeach
            </tbody></table></div></details>
        </section>
        <div class="guide-grid">
            <section class="guide-panel"><h2>Indicators and context</h2><p class="guide-help">Frozen features from the selected dataset. Missing context is not filled with current information.</p>
                <dl>@foreach($snapshot['features'] as $key => $value)<dt>{{ $key }}</dt><dd>{{ is_numeric($value) ? number_format($value, 5) : 'Unavailable' }}</dd>@endforeach</dl>
            </section>
            <section class="guide-panel"><h2>Partial patterns</h2>
                @forelse($snapshot['patterns'] as $pattern)<p><strong>{{ ucwords(str_replace('_', ' ', $pattern['type'])) }}</strong><br>Stage {{ $pattern['stage'] }}/{{ $pattern['length'] }} · {{ number_format(100 * $pattern['progress']) }}% complete · similarity {{ number_format(100 * $pattern['similarity']) }}%</p>
                @empty<p>No supported partial pattern in this snapshot.</p>@endforelse
                <p class="guide-help">Pattern outcomes and later prices remain hidden.</p>
            </section>
        </div>
        <section class="guide-panel"><h2>Your trend assessment</h2>
            @if($review->submitted_at)
                <p><strong>{{ ucwords(str_replace('_', ' ', $review->label)) }}</strong> · {{ $review->confidence === null ? 'Confidence not supplied' : $review->confidence.'% self-rated confidence' }}</p>
                @if($review->reason)<p>{{ $review->reason }}</p>@endif
                <p class="guide-help">Saved {{ $review->submitted_at->utc()->format('Y-m-d H:i:s') }} UTC. Submitted Trend Training labels cannot be changed.</p>
                <h3>Model observation available at that time</h3>
                @if($snapshot['model_observation'])<p>{{ strtoupper($snapshot['model_observation']['action'] === 'hodl' ? 'hold' : $snapshot['model_observation']['action']) }} · {{ str_replace('_', ' ', $snapshot['model_observation']['reason']) }} · recorded {{ gmdate('Y-m-d H:i:s', intdiv($snapshot['model_observation']['recorded_at_ms'], 1000)) }} UTC</p>
                @else<p>No model observation had been recorded by this decision time.</p>@endif
                <a class="guide-button" href="{{ route('human-training.index') }}">Choose another Trend Training snapshot</a>
            @elseif(! $review->expires_at->isFuture())
                <p>This Trend Training review has expired. <a href="{{ route('human-training.index') }}">Request another snapshot</a>.</p>
            @else
                <form method="POST" action="{{ route('human-training.update', $review->review_id) }}">@csrf @method('PUT')
                    <fieldset><legend>Expected market trend</legend><div class="human-labels">
                        @foreach($labels as $label)<label><input type="radio" name="label" value="{{ $label }}" @checked(old('label') === $label)>{{ ucwords(str_replace('_', ' ', $label)) }}</label>@endforeach
                    </div></fieldset>
                    <p class="guide-help">Bull: upward expectation. Bear: downward expectation. Super: stronger expectation. Hold: no clear directional view.</p>
                    <div class="guide-grid"><label for="confidence">Confidence (optional, 0–100)<input id="confidence" name="confidence" type="number" min="0" max="100" step="1" value="{{ old('confidence') }}"></label>
                        <label for="reason">Reason (optional)<textarea id="reason" name="reason" rows="3" maxlength="2000">{{ old('reason') }}</textarea></label></div>
                    <div class="guide-inline"><button type="submit" class="guide-button">Save trend assessment</button><button type="submit" class="review-control" name="label" value="skip">Skip this snapshot</button></div>
                    <p class="guide-help">Expires {{ $review->expires_at->utc()->format('H:i:s') }} UTC. Model output and other trainers’ answers are hidden while you decide.</p>
                </form>
            @endif
        </section>
    </section>
</x-layouts.app>
