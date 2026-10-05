<x-owner.layout title="Failed job">
    <section class="owner-panel">
        <h2>Job details</h2>
        <dl class="owner-details">
            <dt>Job UUID</dt><dd><code>{{ $job->id }}</code></dd>
            <dt>Job</dt><dd><code>{{ $jobName }}</code></dd>
            <dt>Connection</dt><dd>{{ $job->connection }}</dd>
            <dt>Queue</dt><dd>{{ $job->queue }}</dd>
            <dt>Failed at</dt><dd><x-display-time :value="$job->failed_at" /></dd>
        </dl>
        <h3>Failure reason</h3>
        <p>{{ $failureReason ?: 'No exception message was recorded.' }}</p>
        <p class="owner-muted" style="margin:16px 0">Requeue sends this job back to its original queue. Discard removes its failure record without running it.</p>
        <x-owner.failed-job-actions :job="$job->id" />
    </section>
    <section class="owner-panel">
        <h2>Exception and stack trace</h2>
        <pre>{{ $job->exception ?: 'No exception details were recorded.' }}</pre>
    </section>
    <p><a href="{{ route('owner.overview') }}#queue-backlog">Back to queue backlog</a></p>
</x-owner.layout>
