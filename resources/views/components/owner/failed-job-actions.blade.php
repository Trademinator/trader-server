@props(['job', 'view' => false])

<div class="owner-actions" style="margin-top:0" role="group" aria-label="Actions for failed job {{ $job }}">
    @if ($view)
        <form method="GET" action="{{ route('owner.failed-jobs.show', $job) }}">
            <button type="submit">View</button>
        </form>
    @endif
    <form method="POST" action="{{ route('owner.failed-jobs.retry', $job) }}">
        @csrf
        <button type="submit">Requeue</button>
    </form>
    <form method="POST" action="{{ route('owner.failed-jobs.destroy', $job) }}" onsubmit="return confirm('Discard this failed job? It will be removed from the failure list without being run.')">
        @csrf
        @method('DELETE')
        <button type="submit" class="owner-danger">Discard</button>
    </form>
</div>
