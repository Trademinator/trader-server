<x-owner.layout title="Model validation details">
    <section class="owner-panel"><h2>{{ $report['exchange'] ?? '' }} · {{ $report['symbol'] ?? '' }} · {{ $report['period'] ?? '' }}</h2>
        <dl class="owner-details"><dt>Model UUID</dt><dd><code>{{ $model }}</code></dd><dt>Dataset UUID</dt><dd><code>{{ $report['dataset_id'] ?? 'Unknown' }}</code></dd>
            <dt>Status</dt><dd>{{ $report['status'] ?? 'Unknown' }} · {{ $report['reason'] ?? 'Unknown' }}</dd><dt>Knowledge rows</dt><dd>{{ number_format($report['knowledge_rows'] ?? 0) }}</dd><dt>Selected K</dt><dd>{{ $report['k'] ?? 'No eligible K' }}</dd><dt>Built at</dt><dd>{{ $report['created_at'] ?? 'Unknown' }}</dd>
        </dl>
    </section>
    @foreach(['selection' => 'K selection and chronological validation', 'holdout' => 'Separate holdout evaluation', 'patterns' => 'Pattern intelligence', 'lead_lag' => 'Cross-exchange lead / lag', 'human_guidance' => 'Trend Training guidance: machine-only, human-only and combined validation', 'candle_guidance' => 'Candle Training guidance: baseline, human actions and combined validation'] as $key => $label)
        <section class="owner-panel"><h2>{{ $label }}</h2><pre>{{ json_encode($report[$key] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre></section>
    @endforeach
</x-owner.layout>
