<x-owner.layout title="Version status">
    <section class="owner-panel">
        <h2>Current versions</h2>
        <p class="owner-muted">This page reads version constants and configuration only. It does not scan versioned tables or calculate historical row counts.</p>
        <div class="owner-scroll">
            <table class="owner-table">
                <thead><tr><th>Element</th><th>Current version</th><th>Cleanup</th></tr></thead>
                <tbody>
                    @foreach($definitions as $definition)
                        <tr>
                            <td>{{ $definition['name'] }}</td>
                            <td><code>{{ $definition['version'] }}</code></td>
                            <td>
                                @if(isset($definition['scope']))
                                    <form method="POST" action="{{ route('owner.status.purge') }}" onsubmit="return confirm('Delete obsolete {{ addslashes($definition['name']) }} data that differs from the current version? Protected active/referenced data will be retained. This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="scope" value="{{ $definition['scope'] }}">
                                        <button class="owner-danger" type="submit">Purge obsolete</button>
                                    </form>
                                @else
                                    <span class="owner-muted">No versioned DB rows</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="owner-muted">Cleanup remains conservative: current model heads, referenced datasets, and human-training snapshots carrying labels or submitted reviews are protected even when their stored version is old.</p>
    </section>
</x-owner.layout>
