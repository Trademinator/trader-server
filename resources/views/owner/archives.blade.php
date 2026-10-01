<x-owner.layout title="Archive and portable data">
    <section class="owner-panel">
        <h2>Cold-history archive</h2>
        <p class="owner-muted">Archives are queryable cold storage, not backups. M4.3 creates and verifies monthly shards but does not automatically prune hot database rows.</p>
        <dl class="owner-details"><dt>Archive root</dt><dd><code>{{ $root }}</code></dd><dt>Automatic pruning</dt><dd>Disabled</dd><dt>Catalog health</dt><dd>{{ $health['failed'] }} failed · {{ count($health['overlaps']) }} overlaps · {{ count($health['gaps']) }} gaps</dd></dl>
    </section>

    <div class="owner-grid">
        <section class="owner-panel">
            <h2>Create monthly ticker shard</h2>
            <form method="POST" action="{{ route('owner.archives.archive') }}">@csrf
                <label>Exchange <input name="exchange" required maxlength="64"></label>
                <label>Symbol <input name="symbol" required maxlength="64" placeholder="BTC/USD"></label>
                <label>Period <input name="period" required maxlength="8" placeholder="1m"></label>
                <label>Month <input name="month" type="month" required></label>
                <button type="submit">Archive and verify</button>
            </form>
        </section>
        <section class="owner-panel">
            <h2>Recovery tools</h2>
            <form method="POST" action="{{ route('owner.archives.verify') }}">@csrf
                <label>Manifest (blank = all) <input name="manifest" maxlength="1024"></label>
                <button type="submit">Verify</button>
            </form>
            <form method="POST" action="{{ route('owner.archives.rebuild') }}">@csrf
                <button type="submit">Rebuild catalog from manifests</button>
            </form>
        </section>
    </div>

    <section class="owner-panel">
        <h2>Portable export / import</h2>
        <p class="owner-muted">Ordinary portable packages exclude application keys, passwords, API credentials and other secrets.</p>
        <form method="POST" action="{{ route('owner.archives.export') }}">@csrf<button type="submit">Export portable ticker dataset</button></form>
        <form method="POST" action="{{ route('owner.archives.import') }}" enctype="multipart/form-data">@csrf
            <label>Package <input type="file" name="package" accept=".gz,application/gzip" required></label>
            <label><input type="checkbox" name="validate_only" value="1" checked> Validate only</label>
            <button type="submit">Validate / import</button>
        </form>
    </section>

    <section class="owner-panel">
        <h2>Archive catalog</h2>
        <div class="owner-scroll"><table class="owner-table"><thead><tr><th>Dataset</th><th>Market</th><th>Coverage</th><th>Rows</th><th>Health</th><th>Manifest</th><th>Restore</th></tr></thead><tbody>
        @forelse($archives as $item)
            <tr>
                <td>{{ $item->logical_type }}</td>
                <td>{{ $item->exchange }} {{ $item->symbol }} {{ $item->period }}</td>
                <td><x-display-time :value="$item->range_start_ms" unit="milliseconds" /> → <x-display-time :value="$item->range_end_ms" unit="milliseconds" /></td>
                <td>{{ number_format($item->row_count) }}</td>
                <td>{{ $item->verification_state }} @if($item->verification_error)<small>{{ $item->verification_error }}</small>@endif</td>
                <td><code>{{ $item->path }}</code></td>
                <td><form method="POST" action="{{ route('owner.archives.restore') }}">@csrf<input type="hidden" name="manifest" value="{{ $item->path }}"><input name="from_ms" inputmode="numeric" placeholder="from ms"><input name="to_ms" inputmode="numeric" placeholder="to ms"><label><input type="checkbox" name="validate_only" value="1" checked> validate</label><button type="submit">Run</button></form></td>
            </tr>
        @empty<tr><td colspan="7">No archive manifests are catalogued.</td></tr>@endforelse
        </tbody></table></div>
        {{ $archives->links() }}
    </section>
</x-owner.layout>
