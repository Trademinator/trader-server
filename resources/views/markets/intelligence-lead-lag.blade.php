<section class="guide-panel">
    <h2>Cross-exchange lead / lag</h2>
    <p>Tests whether another exchange's movement helps explain a later movement here. Timing is measured in whole closed candles; smaller delays cannot be resolved at this period.</p>
    <p class="guide-help">Only matching spot symbols, quote currencies and selected candle periods are compared. Subscribe to the same pair on another exchange to collect overlapping history. Region and timezone are context, not proof of customer location.</p>
    @if (!isset($report['lead_lag']))
        <p>Build a current model to evaluate cross-exchange evidence.</p>
    @elseif (($report['lead_lag']['report'] ?? []) === [])
        <p>Status: {{ ucwords(str_replace('_', ' ', $report['lead_lag']['status'])) }}.</p>
    @else
        @foreach ($report['lead_lag']['report'] as $leader => $evidence)
            <h3>{{ $leader }} → {{ $item->market->exchange->class }}</h3>
            <p>{{ match ($evidence['status']) {
                'validated' => 'The selected relationship passed later-period checks.',
                'no_incremental_signal' => 'The leader adds no separately identifiable movement beyond the follower. This relationship has zero influence.',
                'insufficient_chronological_blocks' => 'The total count is sufficient, but each purged chronological block still needs enough observations.',
                'insufficient_overlap' => 'More continuous, informative overlapping history is needed in the chronological blocks.',
                'different_selected_period' => 'The exchanges currently use different candle periods.',
                default => 'Evidence is weak or unstable. This relationship has zero influence.',
            } }}</p>
            <x-intelligence-progress label="Overlapping return observations" :value="$evidence['samples']" :target="$evidence['minimum_samples']" native />
            @if (isset($evidence['evaluation']))
                <dl>
                    <dt>Tested delay</dt><dd>{{ $evidence['selected'] }} candles · {{ number_format($evidence['lag_ms'] / 60000, 1) }} minutes · {{ $evidence['direction'] }} direction</dd>
                    <dt>Leader session</dt><dd>{{ $evidence['session'] === 'all' ? 'All hours' : $evidence['session'] }} · {{ $evidence['context']['timezone'] }} ({{ $evidence['context']['timezone_source'] }})</dd>
                    <dt>Later-period observations</dt><dd>{{ $evidence['evaluation']['rows'] }} · {{ number_format($evidence['evaluation']['effective_rows'], 1) }} effective</dd>
                    <dt>Improvement over local / prior baseline</dt><dd>{{ number_format($evidence['evaluation']['skill'] * 100, 1) }}%</dd>
                    <dt>Evidence strength</dt><dd>{{ number_format($evidence['strength'] * 100, 1) }}%</dd>
                </dl>
                @if (isset($signal['lead_lag'][$leader]))
                    <p>Current use: {{ ucwords(str_replace('_', ' ', $signal['lead_lag'][$leader]['reason'])) }}. Influence: {{ number_format($signal['lead_lag'][$leader]['influence'] * 100, 1) }}%.</p>
                @else
                    <p class="guide-help">No current influence is being reported while the main signal is unavailable or abstaining.</p>
                @endif
            @endif
        @endforeach
        @if (($report['lead_lag']['peers_omitted_by_cap'] ?? 0) > 0)
            <p>{{ $report['lead_lag']['peers_omitted_by_cap'] }} additional peers were omitted by the configured build limit.</p>
        @endif
    @endif
    <p class="guide-help">Sample counts alone do not establish a relationship. Direction, baseline improvement and persistence must pass. Eligible shared markets are reevaluated daily when daily refresh is enabled; the intelligence worker must run. No reliable passing-model ETA exists.</p>
    @php
        $nextLeadLag = \Carbon\CarbonImmutable::now(config('app.timezone'))->setTime(3, 45);
        if ($nextLeadLag->isPast()) {
            $nextLeadLag = $nextLeadLag->addDay();
        }
    @endphp
    <p class="guide-help">Next daily lead/lag dispatch: <x-display-time :value="$nextLeadLag" />.</p>
</section>
