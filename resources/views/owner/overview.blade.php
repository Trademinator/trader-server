<x-owner.layout title="Server overview">
    <div class="owner-grid">
        <div class="owner-stat"><span>Registered users</span><strong>{{ \App\Helpers\Decimal::format($users->total) }}</strong><span>{{ $users->verified }} verified · {{ $users->suspended }} suspended</span></div>
        <div class="owner-stat"><span>Active subscriptions</span><strong>{{ \App\Helpers\Decimal::format($subscriptions) }}</strong><span>Across all accounts</span></div>
        <div class="owner-stat"><span>Overdue feeds</span><strong>{{ \App\Helpers\Decimal::format($overdue) }}</strong><span>More than 15 minutes past their next pull</span></div>
        <div class="owner-stat"><span>Requests today</span><strong>{{ \App\Helpers\Decimal::format($traffic->total) }}</strong><span>{{ \App\Helpers\Decimal::format($traffic->errors) }} server errors</span></div>
    </div>
    <section class="owner-panel">
        <h2>Latest activity</h2>
        <dl class="owner-details">
            <dt>Last successful collection</dt><dd>{{ $latestPull ?? 'No collection recorded yet' }}</dd>
            <dt>Last model build</dt><dd>{{ $latestModel ?? 'No model built yet' }}</dd>
            <dt>Action syslog</dt><dd>{{ config('operations.syslog_enabled') ? 'Enabled' : 'Disabled' }} · identifier <code>{{ config('operations.syslog_ident') }}</code></dd>
            <dt>GeoIP database</dt><dd>{{ $geoStatus['status'] }} @if($geoStatus['built_at']) · Built <x-display-time :value="$geoStatus['built_at']" /> @endif</dd>
            <dt>Access retention</dt><dd>{{ config('operations.retention_days') }} days · {{ config('operations.access_enabled') ? 'Recording enabled' : 'Recording disabled' }}</dd>
        </dl>
        <p class="owner-muted" style="margin-top:14px">Syslog delivery depends on the host logging service. Trace IDs connect requests, jobs and operation results.</p>
    </section>
    <div class="owner-grid">
        <section class="owner-panel"><h2>Collector status</h2><table class="owner-table"><thead><tr><th>Status</th><th>Feeds</th></tr></thead><tbody>
            @forelse($feeds as $feed)<tr><td>{{ $feed->status }}</td><td>{{ $feed->total }}</td></tr>@empty<tr><td colspan="2">No feeds yet.</td></tr>@endforelse
        </tbody></table></section>
        <section class="owner-panel"><h2>Current intelligence models</h2>
            <x-knn-readiness :counts="$modelTotals" :total="$modelTotals['total']" />
            <p class="owner-muted">Ready KNN models and current CoinGecko context across all {{ \App\Helpers\Decimal::format($modelTotals['total']) }} current market builds.</p>
        </section>
    </div>
    <section class="owner-panel" id="queue-backlog"><h2>Queue backlog</h2>
        <p class="owner-muted">Configured queue connection: {{ $queueDriver }}. Monitored connections: {{ implode(', ', $queueBacklog['connections']) ?: 'None' }}.</p>
        @foreach($queueBacklog['notices'] as $notice)<p class="owner-muted">{{ $notice }}</p>@endforeach
        @foreach($queueBacklog['errors'] as $error)<p role="status">{{ $error }} Backlog counts may be incomplete.</p>@endforeach
        <div class="owner-scroll"><table class="owner-table"><thead><tr><th>Connection / backend</th><th>Queue</th><th>Waiting</th><th>Delayed</th><th>Reserved</th><th>Total</th><th>Oldest waiting job</th></tr></thead><tbody>
            @forelse($queueBacklog['rows'] as $queue)
                <tr>
                    <td>{{ $queue['connection'] }} / {{ $queue['driver'] }}</td>
                    <td>{{ $queue['driver'] === 'sqs' ? basename($queue['queue']) : $queue['queue'] }}</td>
                    <td>{{ $queue['waiting'] }}</td><td>{{ $queue['delayed'] }}</td><td>{{ $queue['reserved'] }}</td><td>{{ $queue['total'] }}</td>
                    <td>@if($queue['oldest'] !== null)<x-display-time :value="$queue['oldest']" unit="seconds" />@else—@endif</td>
                </tr>
            @empty
                <tr><td colspan="7">{{ $queueBacklog['errors'] === [] ? 'No queued jobs in the monitored connections.' : 'Queue backlog could not be fully read.' }}</td></tr>
            @endforelse
        </tbody></table></div>
        <p class="owner-muted">Reserved jobs have been claimed by a worker. Oldest-job times are shown when available.@if(collect($queueBacklog['rows'])->contains('driver', 'sqs')) SQS counts are approximate.@endif</p>
        <h3>Recent recorded job failures</h3><div class="owner-scroll"><table class="owner-table"><thead><tr>
            @foreach (['uuid' => 'Job UUID', 'connection' => 'Connection / queue', 'exception' => 'Exception', 'failed_at' => 'Failed at'] as $column => $label)
                @php
                    $nextDirection = $failedSort === $column ? ($failedDirection === 'asc' ? 'desc' : 'asc') : ($column === 'failed_at' ? 'desc' : 'asc');
                    $url = route('owner.overview', array_replace($failedFilters, ['failed_sort' => $column, 'failed_direction' => $nextDirection])).'#queue-backlog';
                @endphp
                <th scope="col" @if ($failedSort === $column) aria-sort="{{ $failedDirection === 'asc' ? 'ascending' : 'descending' }}" @endif>
                    <a href="{{ $url }}"
                       aria-label="Sort by {{ $label }}, {{ $nextDirection === 'asc' ? 'ascending' : 'descending' }}"
                       title="Sort by {{ $label }}">
                        {{ $label }} <span aria-hidden="true">{{ $failedSort === $column ? ($failedDirection === 'asc' ? '↑' : '↓') : '↕' }}</span>
                    </a>
                </th>
            @endforeach
            <th scope="col">Actions</th>
        </tr></thead><tbody>
            @forelse($failed as $job)
                <tr>
                    <td><code>{{ $job->uuid }}</code></td>
                    <td>{{ $job->connection }} / {{ $job->queue }}</td>
                    <td><code>{{ $job->exception_name }}</code></td>
                    <td><x-display-time :value="$job->failed_at" /></td>
                    <td><x-owner.failed-job-actions :job="$job->uuid" :view="true" /></td>
                </tr>
            @empty
                <tr><td colspan="5">No recorded failures.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</x-owner.layout>
