<x-layouts.app :title="'Intelligence · '.$item->market->symbol">
    @include('markets.guide-styles')
    @include('markets.review-styles')
    @include('markets.intelligence-styles')
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
                <div class="review-metric">Market state<strong>{{ ucwords(str_replace('_', ' ', $signal['regime'] ?? 'neutral')) }}</strong></div>
                <div class="review-metric">Effective neighbors<strong>{{ number_format($signal['effective_neighbors'], 1) }}</strong></div>
            </div>
            @if (isset($signal['decision_at_ms']))
                <p>Closed-candle decision time: <x-display-time :value="$signal['decision_at_ms']" unit="milliseconds" /></p>
            @endif
            <p class="guide-help">Confidence describes weighted historical agreement and similarity. It is not a calibrated probability of profit. Bull / Bear follow supported directional signals; Super also requires at least 80% confidence and six effective neighbors. The client applies trading fees, balances and execution rules.</p>
            @if ($progress['evidence_evaluated'])
                <x-intelligence-progress label="Effective neighbors required" :value="$signal['effective_neighbors']" :target="$progress['settings']['min_effective_neighbors']" :decimals="1" />
                <x-intelligence-progress label="Weighted agreement × similarity required" :value="max($signal['votes']) * $signal['similarity'] * 100" :target="($report['ensemble']['min_confidence'] ?? $progress['settings']['min_confidence']) * 100" :decimals="1" suffix="%" />
                <p class="guide-help">These evidence meters can rise or fall with each market state. They are not training progress. Published confidence remains zero while the model abstains.</p>
            @endif
        </section>
        @include('markets.intelligence-readiness')
        @include('markets.intelligence-lead-lag')
        @if(isset($report['ensemble']))
            <section class="guide-panel"><h2>Two-KNN scoring</h2>
                <p>The automatic model learns future outcomes. Human Candle learns submitted BUY, HOLD and SELL annotations using the same selected technical features, without CoinGecko context.</p>
                @if(isset($signal['scoring']))
                    <div class="review-table-wrap"><table>
                        <thead><tr><th scope="col">Model</th><th scope="col">Action</th><th scope="col">BUY / HOLD / SELL scores</th><th scope="col">Configured weight</th><th scope="col">Effective weight</th><th scope="col">Evidence</th></tr></thead>
                        <tbody>
                        @foreach(['automatic' => 'Automatic KNN', 'human_candle' => 'Human Candle KNN'] as $key => $label)
                            @php
                                $component = $signal['scoring']['components'][$key];
                            @endphp
                            <tr><th scope="row">{{ $label }}</th><td>{{ $component['reason'] === 'supported' ? strtoupper($component['action']) : 'Abstaining' }}</td>
                                <td>{{ implode(' / ', array_map(fn ($score) => number_format($score * 100, 1).'%', $component['scores'])) }}</td>
                                <td>{{ number_format($signal['scoring']['configured_weights'][$key], 2) }}</td>
                                <td>{{ number_format($signal['scoring']['effective_weights'][$key] * 100, 1) }}%</td>
                                <td>{{ str_replace('_', ' ', $component['reason']) }}</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
                <p>{{ number_format($report['candle_guidance']['samples'] ?? 0) }} eligible annotated candles · {{ str_replace('_', ' ', $report['candle_guidance']['status']) }}</p>
                @if(isset($report['candle_guidance']['holdout']))
                    <p>Human Candle held-out directional annotation agreement: {{ number_format($report['candle_guidance']['holdout']['directional_annotation_agreement'] * 100, 1) }}%.</p>
                @endif
                <p class="guide-help">An abstaining model receives zero effective weight. Supported HOLD votes remain evidence. Human Trend is excluded. Social/news scoring is not connected; CoinGecko is context inside the automatic model. Scores are not probabilities of profit, and neighbor counts are not added across models.</p>
                @can('train-intelligence')<a href="{{ route('human-training.index', ['exchange' => $item->market->exchange->class, 'symbol' => $item->market->symbol, 'period' => $period]) }}">Open human training</a>@endcan
            </section>
        @endif
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
                                <td><x-intelligence-progress label="Pattern candles" :value="$pattern['stage']" :target="$pattern['length']" /></td>
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
                    <dt>Automatic selected K</dt><dd>{{ $report['k'] ?? 'No eligible value' }}</dd>
                    <dt>Automatic knowledge rows</dt><dd>{{ number_format($report['knowledge_rows']) }} retained examples</dd>
                    @if(isset($report['candle_guidance']['knowledge_rows']))
                        <dt>Human Candle K / knowledge rows</dt><dd>{{ $report['candle_guidance']['k'] }} / {{ number_format($report['candle_guidance']['knowledge_rows']) }}</dd>
                    @endif
                    @if (isset($report['training_data']['window']))
                        <dt>History window</dt><dd>{{ number_format($report['training_data']['window']['days']) }} days, starting <x-display-time :value="$report['training_data']['window']['from_ms']" unit="milliseconds" /></dd>
                    @endif
                    <dt>Training cutoff</dt><dd><x-display-time :value="$report['trained_as_of_ms']" unit="milliseconds" /></dd>
                    @if ($report['holdout'])
                        <dt>Automatic later-period precision</dt><dd>{{ number_format($report['holdout']['semantic_precision'] * 100, 1) }}%</dd>
                        <dt>Directional coverage</dt><dd>{{ number_format($report['holdout']['coverage'] * 100, 1) }}%</dd>
                        <dt>Top/bottom contradictions</dt><dd>{{ number_format($report['holdout']['contradiction_rate'] * 100, 1) }}%</dd>
                    @endif
                </dl>
                <p class="guide-help">New builds retain every eligible example within the configured history window. The validation minimum does not cap the knowledge pool.</p>
                @if ($progress['source'])
                    <p>Dataset schema: <strong>{{ $progress['source']['schema'] }}</strong>. Labeled source rows: <strong>{{ isset($progress['source']['source_rows']) ? number_format($progress['source']['source_rows']) : 'Not recorded' }}</strong>.</p>
                    @if (isset($progress['source']['usable_rows']))
                        <x-intelligence-progress label="Usable KNN history at last training" :value="$progress['source']['usable_rows']" :target="$progress['minimum']" native />
                    @else
                        <p>Usable history after pattern exclusions was not recorded by this older model. Rebuild once to see the exact count.</p>
                    @endif
                    @if (($progress['source']['pattern_excluded_rows'] ?? 0) > 0)
                        <p>{{ number_format($progress['source']['pattern_excluded_rows']) }} earlier rows were excluded to keep pattern predictions chronological.</p>
                    @endif
                    @if (($progress['source']['lead_lag_excluded_rows'] ?? 0) > 0)
                        <p>{{ number_format($progress['source']['lead_lag_excluded_rows']) }} earlier rows were excluded so lead/lag evidence was validated before every downstream training decision.</p>
                    @endif
                    <p class="guide-help">With these model settings, {{ number_format($progress['minimum']) }} contiguous, complete, usable rows permit the minimum validation sample; {{ number_format($progress['full_fold_minimum']) }} permit a full tuning block. These estimates include label purging and the separate 20% holdout. Validation must still pass.</p>
                    @if (array_sum($progress['source']['skipped'] ?? []) > 0)
                        <details><summary>Rows excluded from the last dataset</summary>
                            <ul class="intelligence-issues">
                                @foreach ($progress['source']['skipped'] as $reason => $count)
                                    @if ($count > 0)
                                        <li>{{ ucwords(str_replace('_', ' ', $reason)) }}: {{ number_format($count) }}</li>
                                    @endif
                                @endforeach
                            </ul>
                        </details>
                    @endif
                @endif
                <h3>Automatic K selection requirements</h3>
                @if ($progress['tuning'])
                    <p>{{ $report['k'] === null ? 'Closest candidate by number of passed checks' : 'Selected candidate' }}: K = {{ $progress['tuning']['k'] }}. All checks must pass for the same candidate.</p>
                    @include('markets.intelligence-gates', ['gates' => $progress['tuning']['gates']])
                @else
                    <p>No tuning results are available yet.</p>
                @endif
                <h3>Automatic separate later-period validation</h3>
                @if ($progress['holdout'])
                    @include('markets.intelligence-gates', ['gates' => $progress['holdout']])
                @else
                    <p>Not evaluated: K selection must pass first.</p>
                @endif
                @if (($report['patterns'] ?? []) !== [])
                    <h3>Pattern training history</h3>
                    @foreach ($report['patterns'] as $type => $patternReport)
                        <x-intelligence-progress :label="ucwords(str_replace('_', ' ', $type)).' samples'" :value="$patternReport['samples']" :target="$progress['pattern_minimum']" />
                        <p class="guide-help">{{ match ($patternReport['status']) {
                            'validated' => 'Validated. A calibrated pattern model is available.',
                            'insufficient_samples' => 'More examples of this pattern are needed.',
                            'insufficient_chronological_classes' => 'The chronological blocks need enough rows and both completed and failed examples.',
                            'no_improvement_over_prior' => 'Validation did not improve on the prior baseline. More samples do not guarantee a passing model.',
                            default => 'Pattern validation is pending.',
                        } }}</p>
                    @endforeach
                @endif
                <p class="guide-help">K is tuned on earlier chronological folds. These later-period results come from a separate held-out block. Models retrain weekly, after successful backfill, and daily for eligible overlapping markets when lead/lag refresh is enabled.</p>
            </section>
            @if (auth()->user()->isOwner() && isset($report['build_performance']))
                @php
                    $performance = $report['build_performance'];
                    $stageLabels = [
                        'dataset_ms' => 'Dataset construction / loading',
                        'patterns_ms' => 'Pattern models',
                        'lead_lag_ms' => 'Lead / lag',
                        'knn_tuning_ms' => 'KNN tuning',
                        'holdout_ms' => 'Holdout validation',
                        'human_guidance_ms' => 'Human guidance',
                        'candle_guidance_ms' => 'Candle guidance',
                        'persistence_ms' => 'Model persistence',
                    ];
                @endphp
                <section class="guide-panel">
                    <details>
                        <summary><strong>Build performance</strong></summary>
                        <dl>
                            @if (! empty($performance['started_at']))
                                <dt>Last model build</dt><dd><x-display-time :value="$performance['started_at']" /></dd>
                            @endif
                            <dt>Total intelligence build</dt><dd>{{ number_format(($performance['total_ms'] ?? 0) / 1000, 2) }} s</dd>
                            @foreach ($stageLabels as $key => $label)
                                @if (isset($performance['stages'][$key]))
                                    <dt>{{ $label }}</dt><dd>{{ number_format($performance['stages'][$key] / 1000, 2) }} s</dd>
                                @endif
                            @endforeach
                            @if (isset($performance['feature_replay']))
                                <dt>Feature replay</dt><dd>{{ number_format(($performance['feature_replay']['duration_ms'] ?? 0) / 1000, 2) }} s</dd>
                                <dt>Ticker rows processed</dt><dd>{{ number_format($performance['feature_replay']['rows_processed'] ?? 0) }}</dd>
                                <dt>Replay chunks</dt><dd>{{ number_format($performance['feature_replay']['chunks'] ?? 0) }}</dd>
                                <dt>Started from checkpoint</dt><dd>{{ ($performance['feature_replay']['checkpoint_used'] ?? false) ? 'Yes' : 'No' }}</dd>
                            @endif
                        </dl>
                        <p class="guide-help">OWNER-only diagnostics for the most recently published model. Timings are persisted in the model report and also emitted to the action/syslog stream.</p>
                    </details>
                </section>
            @endif
        @endif
    </section>
</x-layouts.app>
