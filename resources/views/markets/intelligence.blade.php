<x-layouts.app :title="'Intelligence · '.$item->market->symbol">
    @include('markets.guide-styles')
    @include('markets.review-styles')
    <section class="pair-guide pair-review">
        <nav class="review-topline" aria-label="Intelligence navigation">
            <a href="{{ route('markets.index') }}">← Your markets</a>
            <a href="{{ route('markets.suggestions.review', ['exchange' => $item->market->exchange->class, 'symbol' => $item->market->symbol]) }}">Review pair</a>
        </nav>
        <header class="guide-hero">
            <p class="review-eyebrow">{{ $item->market->exchange->name }} · {{ $period ?? 'Period pending' }}</p>
            <h1 class="review-title">{{ $item->market->symbol }} intelligence</h1>
            <p>Market signals learned from genuinely closed historical candles.</p>
        </header>
        <section class="guide-panel">
            <h2>Current signal</h2>
            <p class="guide-notice">{{ $explanation }}</p>
            <div class="review-metrics">
                <div class="review-metric">Action<strong>{{ $signal['action'] === 'hodl' ? 'HOLD' : strtoupper($signal['action']) }}</strong></div>
                <div class="review-metric">Confidence<strong>{{ number_format($signal['confidence'] * 100, 1) }}%</strong></div>
                <div class="review-metric">Effective neighbors<strong>{{ number_format($signal['effective_neighbors'], 1) }}</strong></div>
            </div>
            @if (isset($signal['decision_at_ms']))
                <p>Closed-candle decision time: {{ \Carbon\CarbonImmutable::createFromTimestampMs($signal['decision_at_ms'])->utc()->format('Y-m-d H:i:s') }} UTC</p>
            @endif
            <p class="guide-help">Confidence describes weighted historical agreement and similarity. It is not a calibrated probability of profit. The client applies trading fees, balances and execution rules.</p>
        </section>
        <section class="guide-panel">
            <h2>Emerging patterns</h2>
            @if ($signal['patterns'] === [])
                <p>{{ ($signal['patterns_evaluated'] ?? false) ? 'No supported partial pattern is present in the latest closed candles.' : 'Pattern analysis is not available yet.' }}</p>
            @else
                <div class="review-table-wrap">
                    <table>
                        <thead><tr><th scope="col">Pattern</th><th scope="col">Progress</th><th scope="col">Completion probability</th></tr></thead>
                        <tbody>
                        @foreach ($signal['patterns'] as $pattern)
                            <tr>
                                <td>{{ ucwords(str_replace('_', ' ', $pattern['type'])) }}</td>
                                <td>{{ $pattern['stage'] }} of {{ $pattern['length'] }} candles</td>
                                <td>{{ $pattern['completion_probability'] === null ? 'Insufficient validated history' : number_format($pattern['completion_probability'] * 100, 1).'%' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="guide-help">Completion means the remaining candles meet the stated pattern definition. It does not establish a profitable trade.</p>
            @endif
        </section>
        @if ($report)
            <section class="guide-panel">
                <h2>Model validation</h2>
                <dl>
                    <dt>Status</dt><dd>{{ $report['status'] === 'ready' ? 'Validated' : 'Abstaining' }}</dd>
                    <dt>Selected K</dt><dd>{{ $report['k'] ?? 'No eligible value' }}</dd>
                    <dt>Knowledge rows</dt><dd>{{ number_format($report['knowledge_rows']) }}</dd>
                    <dt>Training cutoff</dt><dd>{{ \Carbon\CarbonImmutable::createFromTimestampMs($report['trained_as_of_ms'])->utc()->format('Y-m-d H:i:s') }} UTC</dd>
                    @if ($report['holdout'])
                        <dt>Later-period precision</dt><dd>{{ number_format($report['holdout']['semantic_precision'] * 100, 1) }}%</dd>
                        <dt>Directional coverage</dt><dd>{{ number_format($report['holdout']['coverage'] * 100, 1) }}%</dd>
                        <dt>Top/bottom contradictions</dt><dd>{{ number_format($report['holdout']['contradiction_rate'] * 100, 1) }}%</dd>
                    @endif
                </dl>
                <p class="guide-help">K is tuned on earlier chronological folds. These later-period results come from a separate held-out block. Models retrain weekly.</p>
            </section>
        @endif
    </section>
</x-layouts.app>
