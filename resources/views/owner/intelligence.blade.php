<x-owner.layout title="Global intelligence report">
    <section class="owner-panel">
        <form class="owner-filters" method="GET" action="{{ route('owner.intelligence') }}">
            <label>Versions<select name="history"><option value="0">Current models</option><option value="1" @selected(request('history') == '1')>All model versions</option></select></label>
            <label>Build result<select name="status"><option value="">All results</option><option value="ready" @selected(request('status') === 'ready')>Ready at build</option><option value="abstaining" @selected(request('status') === 'abstaining')>Abstaining</option></select></label><button>Filter models</button>
        </form>
        <p class="owner-muted">{{ \App\Helpers\Decimal::format($models->total()) }} matching models. Markets awaiting their first model are shown in the subscription report.</p>
        <div class="owner-scroll"><table class="owner-table"><thead><tr><th>Market</th><th>Readiness / build reason</th><th>Knowledge rows / K</th><th>Built at</th><th>Report</th></tr></thead><tbody>
            @forelse($models as $model)<tr><td>{{ $model->summary['exchange'] ?? 'Unknown' }} · {{ $model->summary['symbol'] ?? 'Unknown' }}<small>{{ $model->summary['period'] ?? 'Unknown' }} · {{ $model->current_id ? 'Current model' : 'Historical version' }}</small></td><td><x-knn-readiness :report="$model->summary" :coingecko="$model->coingecko" /><small>{{ $model->summary['reason'] ?? 'Unknown' }}</small></td><td>{{ \App\Helpers\Decimal::format($model->summary['knowledge_rows'] ?? 0) }} / {{ $model->summary['k'] ?? 'Not selected' }}</td><td><x-display-time :value="$model->created_at" /></td><td><a href="{{ route('owner.intelligence.show', $model->model_id) }}">Validation details</a></td></tr>@empty<tr><td colspan="5">No matching models. Collection and feature generation must precede training.</td></tr>@endforelse
        </tbody></table></div>{{ $models->links() }}
    </section>
</x-owner.layout>
