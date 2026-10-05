<x-owner.layout title="Global intelligence report">
    <section class="owner-panel">
        <form class="owner-filters" method="GET" action="{{ route('owner.intelligence') }}" role="search">
            <label>Search models<input type="search" name="q" value="{{ $filters['q'] }}" maxlength="120" placeholder="Exchange, pair, period or build reason"></label>
            <label>Versions<select name="history"><option value="0">Current models</option><option value="1" @selected(request('history') == '1')>All model versions</option></select></label>
            <label>Build result<select name="status"><option value="">All results</option><option value="ready" @selected(request('status') === 'ready')>Ready at build</option><option value="abstaining" @selected(request('status') === 'abstaining')>Abstaining</option></select></label>
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <button type="submit">Filter models</button>
            @if ($filters['q'] !== '')<a href="{{ route('owner.intelligence', array_diff_key($filters, ['q' => true])) }}">Clear search</a>@endif
        </form>
        <p class="owner-muted">{{ \App\Helpers\Decimal::format($models->total()) }} matching models. Markets awaiting their first model are shown in the subscription report.</p>
        <div class="owner-scroll">
            <table class="owner-table">
                <thead>
                    <tr>
                        @foreach (['market' => 'Market', 'reason' => 'Readiness / build reason', 'knowledge_rows' => 'Knowledge rows', 'k' => 'K', 'created_at' => 'Built at'] as $column => $label)
                            @php
                                $nextDirection = $sort === $column ? ($direction === 'asc' ? 'desc' : 'asc') : ($column === 'created_at' ? 'desc' : 'asc');
                                $sortLabel = $column === 'reason' ? 'Build reason' : $label;
                            @endphp
                            <th scope="col" @if ($sort === $column) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                <a href="{{ route('owner.intelligence', array_replace($filters, ['sort' => $column, 'direction' => $nextDirection])) }}"
                                   aria-label="Sort by {{ $sortLabel }}, {{ $nextDirection === 'asc' ? 'ascending' : 'descending' }}"
                                   title="Sort by {{ $sortLabel }}">
                                    {{ $label }} <span aria-hidden="true">{{ $sort === $column ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                </a>
                            </th>
                        @endforeach
                        <th scope="col">Report</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($models as $model)
                        <tr>
                            <td>{{ $model->summary['exchange'] ?? 'Unknown' }} · {{ $model->summary['symbol'] ?? 'Unknown' }}<small>{{ $model->summary['period'] ?? 'Unknown' }} · {{ $model->current_id ? 'Current model' : 'Historical version' }}</small></td>
                            <td><x-knn-readiness :report="$model->summary" :coingecko="$model->coingecko" /><small>{{ $model->summary['reason'] ?? 'Unknown' }}</small></td>
                            <td>{{ \App\Helpers\Decimal::format($model->summary['knowledge_rows'] ?? 0) }}</td>
                            <td>{{ $model->summary['k'] ?? 'Not selected' }}</td>
                            <td><x-display-time :value="$model->created_at" /></td>
                            <td><a href="{{ route('owner.intelligence.show', $model->model_id) }}">Validation details</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No matching models. Collection and feature generation must precede training.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $models->links() }}
    </section>
</x-owner.layout>
