<x-owner.layout title="Version status">
    <section class="owner-panel">
        <h2>Current versions</h2>
        <p class="owner-muted">Compatibility versions compiled into this server. These are the versions new snapshots, features and models are expected to use.</p>
        <div class="owner-scroll">
            <table class="owner-table">
                <thead><tr><th>Element</th><th>Current version</th></tr></thead>
                <tbody>
                    @foreach($definitions as $definition)
                        <tr><td>{{ $definition['name'] }}</td><td><code>{{ $definition['version'] }}</code></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="owner-panel">
        <h2>Stored versions</h2>
        <p class="owner-muted">“Purgeable” is intentionally conservative. Active heads, referenced datasets, archive catalog entries, and human snapshots carrying training are protected.</p>
        <div class="owner-scroll">
            <table class="owner-table">
                <thead><tr><th>Element</th><th>Stored version</th><th>Status</th><th>Rows</th><th>Latest</th><th>Purgeable</th><th>Action</th></tr></thead>
                <tbody>
                    @forelse($stored as $item)
                        <tr>
                            <td>{{ $item->family }}</td>
                            <td><code>{{ $item->version }}</code></td>
                            <td><span class="owner-badge">{{ $item->current ? 'Current' : 'Historical' }}</span></td>
                            <td>{{ AppHelpersDecimal::format($item->total) }}</td>
                            <td><x-display-time :value="$item->latest" /></td>
                            <td>{{ AppHelpersDecimal::format($item->purgeable) }}</td>
                            <td>
                                @if($item->scope && $item->purgeable > 0)
                                    <form method="POST" action="{{ route('owner.status.purge') }}" onsubmit="return confirm('Purge all currently eligible {{ addslashes($item->family) }} records? This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="scope" value="{{ $item->scope }}">
                                        <button class="owner-danger" type="submit">Purge eligible</button>
                                    </form>
                                @else
                                    <span class="owner-muted">Protected / none</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No versioned records are stored yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-owner.layout>
