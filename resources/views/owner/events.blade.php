<x-owner.layout title="Fork event review">
    <section class="owner-panel">
        <h2>GDELT candidates</h2>
        <p class="owner-muted">GDELT is the only event source in this stage. Candidates come from the open 15-minute GKG stream and are classified from GKG headline metadata only. Open the original source and investigate it before choosing YES or NO. Your decision does not overwrite the machine evidence. Data source: <a href="https://www.gdeltproject.org/" target="_blank" rel="noopener noreferrer">The GDELT Project</a>.</p>
        <form class="owner-filters" method="GET">
            <label>Decision
                <select name="decision">
                    @foreach (['pending' => 'Pending', 'yes' => 'Confirmed YES', 'no' => 'Rejected NO', 'all' => 'All'] as $value => $label)
                        <option value="{{ $value }}" @selected($decision === $value)>{{ $label }} ({{ $counts[$value] }})</option>
                    @endforeach
                </select>
            </label>
            <button type="submit">Filter</button>
        </form>
    </section>

    <section class="owner-panel">
        <div class="owner-scroll">
            <table class="owner-table">
                <thead><tr><th>Detected</th><th>Candidate</th><th>GDELT evidence</th><th>Source</th><th>Owner decision</th></tr></thead>
                <tbody>
                @forelse ($items as $item)
                    @php
                        $type = str_replace('_', ' ', $item->event_type);
                        $symbols = $item->matched_symbols ?? [];
                        $families = $item->machine_evidence['families'] ?? [];
                        $batch = $item->machine_evidence['gkg_batch'] ?? null;
                    @endphp
                    <tr>
                        <td><x-display-time :value="$item->source_seen_at" fallback="Unknown" /><small>Stored <x-display-time :value="$item->created_at" /></small></td>
                        <td>
                            <span class="owner-badge">{{ strtoupper($type) }}</span>
                            <small>Classifier confidence {{ \App\Helpers\Decimal::format((float) $item->machine_confidence * 100, 0) }}%</small>
                            @if ($symbols !== [])<small>Matched subscribed symbols: {{ implode(', ', $symbols) }}</small>@endif
                        </td>
                        <td>
                            @if ($families !== [])<small>Headline signals: {{ implode(', ', $families) }}</small>@endif
                            @if ($batch)<small>GKG batch: {{ $batch }}</small>@endif
                            <small>Subtype may remain UNKNOWN when the headline does not contain enough evidence. Use the linked source for the manual decision.</small>
                        </td>
                        <td>
                            <a href="{{ $item->source_url }}" target="_blank" rel="noopener noreferrer">{{ $item->source_title }}</a>
                            <small>{{ $item->source_domain ?? 'Unknown domain' }}@if($item->source_language) · language {{ $item->source_language }}@endif</small>
                        </td>
                        <td>
                            @if ($item->owner_decision)
                                <span class="owner-badge">{{ strtoupper($item->owner_decision) }}</span>
                                <small>Reviewed by {{ $item->reviewer?->name ?? 'owner' }} · <x-display-time :value="$item->reviewed_at" /></small>
                            @else
                                <span class="owner-badge">PENDING</span>
                            @endif
                            <div class="owner-actions">
                                <form method="POST" action="{{ route('owner.events.update', $item) }}">@csrf @method('PUT')
                                    <input type="hidden" name="decision" value="yes">
                                    <button type="submit">Confirm YES</button>
                                </form>
                                <form method="POST" action="{{ route('owner.events.update', $item) }}">@csrf @method('PUT')
                                    <input type="hidden" name="decision" value="no">
                                    <button class="owner-danger" type="submit">Confirm NO</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">No GDELT event candidates match this filter.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $items->links() }}
    </section>
</x-owner.layout>
