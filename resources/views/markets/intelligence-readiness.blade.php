<section class="guide-panel">
    <h2>What is missing?</h2>
    <p class="guide-notice">{{ $progress['action'] }}</p>
    @if (!$progress['evidence_evaluated'])
        <p>The current candle has not reached KNN voting. Confidence and effective neighbors are fallback zeros, not measured trading evidence.</p>
    @endif
    @if ($progress['issues'] !== [])
        <ul class="intelligence-issues">
            @foreach ($progress['issues'] as $issue)
                <li>{{ $issue }}</li>
            @endforeach
        </ul>
    @endif
    @if ($progress['history'])
        <x-intelligence-progress label="Potential training rows now (estimate)" :value="$progress['history']['potential']" :target="$progress['minimum']" native />
        <p class="guide-help">{{ number_format($progress['history']['closed']) }} current-version closed feature rows in the recent build window; {{ number_format($progress['history']['complete']) }} contain every selected feature. {{ number_format($progress['history']['immature']) }} complete rows are reserved for inference or still waiting for their {{ $progress['horizon'] }}-candle outcome.</p>
        <p class="guide-help">Potential rows are an upper bound before source-candle checks, semantic warm-up, pattern and lead/lag exclusions. The actual model counts below come from the last training run.</p>
        @if ($progress['history']['latest_ms'])
            <p>Latest closed feature: <time datetime="{{ \Carbon\CarbonImmutable::createFromTimestampMs($progress['history']['latest_ms'])->toIso8601String() }}">{{ \Carbon\CarbonImmutable::createFromTimestampMs($progress['history']['latest_ms'])->utc()->format('Y-m-d H:i:s') }} UTC</time></p>
        @endif
    @endif
    <h3>ETA</h3>
    @if ($progress['eta'])
        <p><strong>Earliest data estimate: {{ $progress['eta']->diffForHumans() }}</strong> · <time datetime="{{ $progress['eta']->toIso8601String() }}">{{ $progress['eta']->format('Y-m-d H:i:s') }} UTC</time></p>
    @endif
    <p>{{ $progress['eta_note'] }}</p>
    <p><strong>Validated-model ETA: unknown.</strong> Reaching the row minimum permits evaluation; it does not guarantee acceptable precision, coverage or similar neighbors.</p>
    @if ($progress['next_training'])
        <p>Next weekly training dispatch: <time datetime="{{ $progress['next_training']->toIso8601String() }}">{{ $progress['next_training']->format('Y-m-d H:i:s') }} UTC</time> ({{ $progress['next_training']->diffForHumans() }}). This is a schedule, not confirmation that a worker is running.</p>
    @endif
    <p class="guide-help">M4 jobs require a worker draining the <code>{{ $progress['queue'] }}</code> queue. Training is separate from M2 feature collection. Refresh this page to update the live counts; model counts change only after a rebuild.</p>
    @if ($period)
        <details>
            <summary>How to build or troubleshoot this model</summary>
            <p>After collection and M2 features are available, run a direct build for this market:</p>
            <pre class="intelligence-command"><code>php artisan trademinator:knn-build {{ escapeshellarg($item->market->exchange->class) }} {{ escapeshellarg($item->market->symbol) }} {{ escapeshellarg($period) }}</code></pre>
            <p>This command uses the default <code>core</code> schema. For automatic builds, run <code>php artisan trademinator:dispatch-market-intelligence</code> and the cron worker documented in <code>docs/CRONTABS.md</code>. Review <code>php artisan queue:failed</code> if a build never appears. A completed weekly generation, including an abstaining model, is not rebuilt by redispatching in the same week; use the direct command after adding history.</p>
        </details>
    @endif
</section>
