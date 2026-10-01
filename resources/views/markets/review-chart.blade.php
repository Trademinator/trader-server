            <section class="guide-panel" aria-labelledby="history-heading">
                <div class="review-topline"><h2 id="history-heading">Closed-candle price history</h2><span class="guide-badge">{{ $evidence['period'] ?? 'Awaiting data' }}</span></div>
                <p>Price in {{ $item['quote'] }} per {{ $item['base'] }}. Times in <x-timezone-label />. Only completed candles are shown.</p>
                <div data-review-chart data-review-type="{{ ($technicalReview ?? false) ? 'technical' : 'preference' }}" data-url="{{ $reviewUrl }}" data-evidence="{{ json_encode($evidence, JSON_THROW_ON_ERROR) }}" data-tick-size="{{ $market['tick_size'] }}" data-quote="{{ $item['quote'] }}" data-symbol="{{ $item['symbol'] }}">
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
                            {{ $evidence['candles'] }} closed candles · <x-display-time :value="$evidence['from']" precision="minutes" />–<x-display-time :value="$evidence['through']" precision="minutes" />
                            @if ($evidence['stale']) · Stale history @endif
                        @else
                            No closed candles available yet. Collection may still be pending or inactive.
                        @endif
                    </p>
                    <noscript><p>The interactive chart requires JavaScript. The historical evidence and candle table below remain available.</p></noscript>
                </div>
                <p class="guide-help">Updates read candles already collected by Trademinator, rather than streaming trades from the exchange. A refresh does not start collection. New data arrives through the existing scheduled collector.</p>
                <p class="guide-help">TradingView Lightweight Charts™ · Copyright (с) 2025 TradingView, Inc. · <a href="https://www.tradingview.com/" target="_blank" rel="noopener noreferrer">TradingView Lightweight Charts™</a>. TradingView is the chart library provider; the market data comes from Trademinator.</p>
                <details><summary>Recent candle values at page load</summary>
                    <div class="review-table-wrap"><table>
                        <caption class="guide-help">Latest {{ min(10, count($evidence['series'])) }} closed candles. OHLC prices in {{ $item['quote'] }}; volume as reported by the exchange.</caption>
                        <thead><tr><th scope="col">Open time (<x-timezone-label />)</th><th scope="col">Open</th><th scope="col">High</th><th scope="col">Low</th><th scope="col">Close</th><th scope="col">Volume</th></tr></thead>
                        <tbody>@forelse (array_reverse(array_slice($evidence['series'], -10)) as $candle)
                            <tr><th scope="row"><x-display-time :value="$candle['time']" unit="seconds" precision="minutes" /></th><td>{{ $number($candle['open']) }}</td><td>{{ $number($candle['high']) }}</td><td>{{ $number($candle['low']) }}</td><td>{{ $number($candle['close']) }}</td><td>{{ $number($candle['volume']) }}</td></tr>
                        @empty<tr><td colspan="6">No valid closed candles are stored for this sample.</td></tr>@endforelse</tbody>
                    </table></div>
                </details>
            </section>
