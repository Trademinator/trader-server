@php
    $outcomeAutomatic = $report['outcome']['algorithmic'] ?? [];
    $outcomeHoldout = $outcomeAutomatic['holdout'] ?? [];
    $actionSources = [
        'Algorithmic Action KNN' => $report['action']['algorithmic'] ?? [],
        'Human Action KNN' => $report['action']['human'] ?? [],
    ];
@endphp
<p class="owner-muted">Readiness is evaluated separately for Outcome and Action. The following are retrospective, finalized-label holdouts; they do not measure live profitability. A supported HOLD differs from an abstention.</p>
<div class="owner-scroll">
    <table class="owner-table">
        <thead><tr><th scope="col">Outcome source</th><th scope="col">Status</th><th scope="col">Five-class F1</th><th scope="col">Coverage</th><th scope="col">Failed gates</th></tr></thead>
        <tbody>
            <tr>
                <th scope="row">Algorithmic Outcome KNN (K {{ $outcomeAutomatic['k'] ?? '—' }})</th>
                <td>{{ $outcomeAutomatic['reason'] ?? $outcomeAutomatic['status'] ?? 'Unavailable' }}</td>
                <td>{{ isset($outcomeHoldout['macro_f1']) ? \App\Helpers\Decimal::format($outcomeHoldout['macro_f1'] * 100, 2).'%' : '—' }}</td>
                <td>{{ isset($outcomeHoldout['coverage']) ? \App\Helpers\Decimal::format($outcomeHoldout['coverage'] * 100, 2).'%' : '—' }}</td>
                <td>{{ implode(', ', $outcomeHoldout['failed_gates'] ?? []) ?: 'None reported' }}</td>
            </tr>
            <tr>
                <th scope="row">Human Outcome KNN</th>
                <td>{{ $report['outcome']['human']['status'] ?? 'Unavailable' }}</td>
                <td colspan="3">Independent human assessment source</td>
            </tr>
        </tbody>
    </table>
</div>
@foreach ($actionSources as $sourceName => $source)
    @php($holdout = $source['holdout'] ?? [])
    <h3>{{ $sourceName }}</h3>
    <div class="owner-scroll">
        <table class="owner-table">
            <tbody>
                <tr><th scope="row">Readiness / reason</th><td>{{ $source['reason'] ?? $source['status'] ?? 'Unavailable' }} · {{ $holdout['validation_status'] ?? 'No holdout' }}</td></tr>
                <tr><th scope="row">Historical evidence</th><td>{{ \App\Helpers\Decimal::format($holdout['evaluated'] ?? 0) }} evaluated · {{ \App\Helpers\Decimal::format($holdout['directional_opportunities'] ?? 0) }} BUY/SELL opportunities · {{ \App\Helpers\Decimal::format($holdout['directional'] ?? 0) }} supported directional predictions</td></tr>
                <tr><th scope="row">Supported HOLD versus abstention</th><td>{{ \App\Helpers\Decimal::format($holdout['supported_holds'] ?? 0) }} supported HOLD ({{ \App\Helpers\Decimal::format($holdout['correct_holds'] ?? 0) }} correct) · {{ \App\Helpers\Decimal::format($holdout['abstained'] ?? 0) }} abstentions</td></tr>
                <tr><th scope="row">Natural BUY / HOLD / SELL labels</th><td>BUY {{ \App\Helpers\Decimal::format($holdout['natural_class_counts']['buy'] ?? 0) }} · HOLD {{ \App\Helpers\Decimal::format($holdout['natural_class_counts']['hodl'] ?? 0) }} · SELL {{ \App\Helpers\Decimal::format($holdout['natural_class_counts']['sell'] ?? 0) }}</td></tr>
                <tr><th scope="row">Directional precision</th><td>{{ isset($holdout['semantic_precision']) || isset($holdout['directional_annotation_agreement']) ? \App\Helpers\Decimal::format(($holdout['semantic_precision'] ?? $holdout['directional_annotation_agreement']) * 100, 2).'%' : '—' }} · Wilson 95% lower {{ isset($holdout['directional_wilson_95']['lower']) ? \App\Helpers\Decimal::format($holdout['directional_wilson_95']['lower'] * 100, 2).'%' : '—' }}</td></tr>
                <tr><th scope="row">Directional disagreements</th><td>Opposite actions {{ \App\Helpers\Decimal::format($holdout['opposite_action_predictions'] ?? 0) }} · BUY/SELL on HOLD {{ \App\Helpers\Decimal::format($holdout['directional_predictions_on_hold'] ?? 0) }}</td></tr>
                <tr><th scope="row">Failed gates</th><td>{{ implode(', ', $holdout['failed_gates'] ?? []) ?: 'None reported' }}</td></tr>
            </tbody>
        </table>
        @if (! empty($holdout['by_action']))
            <table class="owner-table">
                <thead><tr><th scope="col">Action</th><th scope="col">Predicted</th><th scope="col">Correct</th><th scope="col">Precision</th><th scope="col">Recall</th><th scope="col">False positives</th><th scope="col">Wilson 95% lower</th></tr></thead>
                <tbody>
                    @foreach (['buy' => 'BUY', 'sell' => 'SELL'] as $action => $label)
                        @php($metrics = $holdout['by_action'][$action] ?? [])
                        <tr>
                            <th scope="row">{{ $label }}</th>
                            <td>{{ \App\Helpers\Decimal::format($metrics['predicted'] ?? 0) }}</td>
                            <td>{{ \App\Helpers\Decimal::format($metrics['correct'] ?? 0) }}</td>
                            <td>{{ isset($metrics['precision']) ? \App\Helpers\Decimal::format($metrics['precision'] * 100, 2).'%' : '—' }}</td>
                            <td>{{ isset($metrics['recall']) ? \App\Helpers\Decimal::format($metrics['recall'] * 100, 2).'%' : '—' }}</td>
                            <td>{{ \App\Helpers\Decimal::format($metrics['false_positives'] ?? 0) }}</td>
                            <td>{{ isset($metrics['precision_wilson_95']['lower']) ? \App\Helpers\Decimal::format($metrics['precision_wilson_95']['lower'] * 100, 2).'%' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endforeach
