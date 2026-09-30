<x-layouts.app title="Human training">
    @include('markets.guide-styles')
    @include('markets.review-styles')
    <section class="pair-guide pair-review">
        <header class="guide-hero"><p class="review-eyebrow">M4.4 · Human-guided learning</p><h1>Human training</h1>
            <p>Review a historical market as it looked at a closed candle. Future prices and objective outcome labels stay hidden.</p></header>
        @if(session('status'))<p class="guide-notice" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="guide-notice guide-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <section class="guide-panel">
            <h2>Label a snapshot</h2>
            <p>You have submitted {{ number_format($completed) }} labels. Your answer is a market opinion; it does not place an order or replace historical outcomes.</p>
            @if($pending)
                <a class="guide-button" href="{{ route('human-training.show', $pending->review_id) }}">Continue your current snapshot</a>
            @elseif($datasets === [])
                <p>No current semantic dataset is available. The server owner must build market features and run <code>trademinator:knn-build</code> first.</p>
            @else
                <form method="POST" action="{{ route('human-training.store') }}">@csrf
                    <label for="dataset">Market and frozen dataset</label>
                    <select id="dataset" name="dataset" required>
                        @foreach($datasets as $dataset)<option value="{{ $dataset['dataset_id'] }}" @selected(old('dataset') === $dataset['dataset_id'])>{{ $dataset['exchange'] }} · {{ $dataset['symbol'] }} · {{ $dataset['period'] }} · {{ number_format($dataset['rows']) }} samples · {{ substr($dataset['dataset_id'], 0, 8) }}</option>@endforeach
                    </select>
                    <p class="guide-help">A random unseen snapshot is selected. Model output is revealed only after submission. Each snapshot accepts one answer from you; other trainers can assess it independently.</p>
                    <button class="guide-button" type="submit">Start a random snapshot</button>
                </form>
            @endif
        </section>
        <section class="guide-panel"><h2>How your labels are used</h2>
            <p>Super Bull, Bull, Hold, Bear and Super Bear describe your expectation over the displayed candle horizon. Optional confidence describes your own certainty.</p>
            <p>The next model build can train an auxiliary opinion model from agreed labels on earlier candles. Machine-only, human-only and combined models are compared on later historical candles. Combined intelligence is used only after passing both tuning and holdout improvement checks. Human-only predictions cannot issue a Server signal.</p>
            <p class="guide-help">These are retrospective research comparisons, not predictions recorded live in the past. Agreement between trainers measures consistency, not trading ability or expected profit.</p>
        </section>
        @if($statistics !== null)
            <section class="guide-panel"><h2>Trainer agreement</h2>
                <p>Latest {{ number_format($statistics['snapshots']) }} reviewed snapshots by candle time (maximum 1,000): {{ $statistics['shared'] }} reviewed by multiple trainers; {{ $statistics['disputed'] }} with differing labels.</p>
                <div class="review-table-wrap"><table><thead><tr><th>Trainer UUID</th><th>Labels</th><th>Agreement with peers</th></tr></thead><tbody>
                    @forelse($statistics['trainers'] as $id => $trainer)<tr><td><code>{{ $id }}</code></td><td>{{ $trainer['labels'] }}</td><td>{{ $trainer['peer_comparisons'] ? number_format(100 * $trainer['peer_agreements'] / $trainer['peer_comparisons'], 1).'% ('.$trainer['peer_comparisons'].' comparisons)' : 'Awaiting independent reviews' }}</td></tr>
                    @empty<tr><td colspan="3">No submitted labels yet.</td></tr>@endforelse
                </tbody></table></div>
                <p class="guide-help">All five labels are compared exactly. Trainer confidence does not increase voting weight. Ties and insufficient agreement are excluded from training.</p>
                <form method="POST" action="{{ route('human-training.export') }}">@csrf<button class="review-control" type="submit">Export snapshots and labels</button></form>
                <p><a href="{{ route('owner.intelligence') }}">View model validation and human-guidance comparisons</a></p>
            </section>
        @endif
    </section>
</x-layouts.app>
