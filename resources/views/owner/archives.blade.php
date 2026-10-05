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
        <h2>Multipart portable export / import</h2>
        <p class="owner-muted">Portable sets exclude application keys, passwords, exchange/API credentials and other secrets. Each gzip part is independently verifiable and bounded; no server-side concatenation is required.</p>
        <form method="POST" action="{{ route('owner.archives.export') }}">@csrf<button type="submit">Queue multipart ticker export</button></form>
        <form method="POST" action="{{ route('owner.archives.import') }}" enctype="multipart/form-data">@csrf
            <label>Manifest <input type="file" name="manifest" accept=".json,application/json" required></label>
            <label><input type="checkbox" name="validate_only" value="1" checked> Validate only (do not insert rows)</label>
            <button type="submit">Create import set</button>
        </form>
        <p class="owner-muted">For an import, upload the manifest first, then every part listed by it. Database mutation begins only after all parts have passed checksum, size, row-boundary and format verification.</p>
    </section>

    <section class="owner-panel">
        <h2>Recent portable transfers</h2>
        <div class="owner-scroll"><table class="owner-table"><thead><tr><th>Type</th><th>Status</th><th>Progress</th><th>Rows</th><th>Files</th><th>Action</th></tr></thead><tbody>
        @forelse($portableTransfers as $transfer)
            <tr>
                <td>{{ ucfirst($transfer->direction) }}</td>
                <td>{{ $transfer->status }} @if($transfer->error)<small>{{ $transfer->error }}</small>@endif</td>
                <td>
                    @if($transfer->direction === 'export')
                        {{ \App\Helpers\Decimal::format($transfer->completed_parts) }} parts built
                    @else
                        {{ \App\Helpers\Decimal::format($transfer->verified_parts) }} / {{ \App\Helpers\Decimal::format($transfer->expected_parts) }} verified
                        @if($transfer->status === 'importing' || $transfer->status === 'completed') · {{ \App\Helpers\Decimal::format($transfer->completed_parts) }} imported @endif
                    @endif
                </td>
                <td>{{ \App\Helpers\Decimal::format($transfer->total_rows) }}</td>
                <td>
                    @if($transfer->direction === 'export' && $transfer->status === 'ready')
                        <a href="{{ route('owner.archives.portable.manifest', $transfer->portable_archive_transfer_id) }}">manifest.json</a>
                        @foreach($transfer->parts as $part)
                            · <a href="{{ route('owner.archives.portable.part', [$transfer->portable_archive_transfer_id, $part->sequence]) }}">{{ $part->file_name }}</a>
                        @endforeach
                    @elseif($transfer->direction === 'import')
                        @foreach($transfer->parts as $part)
                            <div><code>{{ $part->file_name }}</code> — {{ $part->status }}</div>
                        @endforeach
                    @else
                        —
                    @endif
                </td>
                <td>
                    @if($transfer->direction === 'import' && $transfer->status === 'validated')
                        <form method="POST" action="{{ route('owner.archives.import.begin', $transfer->portable_archive_transfer_id) }}">@csrf
                            <button type="submit">Import verified set</button>
                        </form>
                    @elseif($transfer->direction === 'import' && in_array($transfer->status, ['uploading', 'verifying'], true))
                        <form method="POST" action="{{ route('owner.archives.import.part', $transfer->portable_archive_transfer_id) }}" enctype="multipart/form-data">@csrf
                            <input type="file" name="part" accept=".gz,application/gzip" required>
                            <button type="submit">Upload one part</button>
                        </form>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6">No multipart portable transfers yet.</td></tr>
        @endforelse
        </tbody></table></div>
    </section>

    <section class="owner-panel">
        <h2>Archive catalog</h2>
        <div class="owner-scroll"><table class="owner-table"><thead><tr><th>Dataset</th><th>Market</th><th>Coverage</th><th>Rows</th><th>Health</th><th>Manifest</th><th>Restore</th></tr></thead><tbody>
        @forelse($archives as $item)
            <tr>
                <td>{{ $item->logical_type }}</td>
                <td>{{ $item->exchange }} {{ $item->symbol }} {{ $item->period }}</td>
                <td><x-display-time :value="$item->range_start_ms" unit="milliseconds" /> → <x-display-time :value="$item->range_end_ms" unit="milliseconds" /></td>
                <td>{{ \App\Helpers\Decimal::format($item->row_count) }}</td>
                <td>{{ $item->verification_state }} @if($item->verification_error)<small>{{ $item->verification_error }}</small>@endif</td>
                <td><code>{{ $item->path }}</code></td>
                <td><form method="POST" action="{{ route('owner.archives.restore') }}">@csrf<input type="hidden" name="manifest" value="{{ $item->path }}"><input name="from_ms" inputmode="numeric" placeholder="from ms"><input name="to_ms" inputmode="numeric" placeholder="to ms"><label><input type="checkbox" name="validate_only" value="1" checked> validate</label><button type="submit">Run</button></form></td>
            </tr>
        @empty<tr><td colspan="7">No archive manifests are catalogued.</td></tr>@endforelse
        </tbody></table></div>
        {{ $archives->links() }}
    </section>
</x-owner.layout>
