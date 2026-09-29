@php
    $explanations = [
        'Evaluated rows' => 'Closed historical examples tested using only earlier training data whose outcomes were already known. Includes BUY, SELL and HOLD results. The minimum ensures validation uses enough examples.',
        'Directional predictions' => 'Number of evaluated examples where the model issued BUY or SELL. HOLD and abstentions do not count. This prevents a model from passing without making enough directional predictions.',
        'Semantic precision' => 'Correct BUY or SELL predictions divided by all BUY or SELL predictions. Correct means matching the historical turning-point label. For example, 6 correct predictions out of 10 means 60%. This does not measure profit after trading costs.',
        'Directional coverage' => 'BUY or SELL predictions divided by all evaluated rows, including HOLD and abstentions. For example, 10 directional predictions across 100 evaluated rows means 10% coverage.',
        'Top/bottom contradictions' => 'The percentage of BUY or SELL predictions that buy at a recent top or sell at a recent bottom, as defined by the historical labeling rules. Lower is better. A zero value with no directional predictions does not establish a valid model.',
    ];
@endphp
<div class="review-table-wrap">
    <table>
        <thead><tr><th scope="col">Requirement</th><th scope="col">Observed</th><th scope="col">Required</th><th scope="col">Result</th></tr></thead>
        <tbody>
        @foreach ($gates as $gate)
            <tr>
                <th scope="row">
                    {{ $gate['label'] }}
                    <button type="button" class="intelligence-help" data-intelligence-tooltip
                        aria-label="Explain {{ $gate['label'] }}" title="{{ $explanations[$gate['label']] }}">
                        <span aria-hidden="true">?</span>
                    </button>
                </th>
                <td>{{ number_format($gate['value'] * ($gate['percent'] ? 100 : 1), $gate['percent'] ? 1 : 0) }}{{ $gate['percent'] ? '%' : '' }}</td>
                <td>{{ $gate['maximum'] ? 'At most' : 'At least' }} {{ number_format($gate['target'] * ($gate['percent'] ? 100 : 1), $gate['percent'] ? 1 : 0) }}{{ $gate['percent'] ? '%' : '' }}</td>
                <td>{{ $gate['passed'] ? 'Passed' : 'Missing / failed' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
