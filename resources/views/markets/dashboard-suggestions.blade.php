@if ($failure)
    <p class="guide-notice">{{ $failure }}</p>
@elseif (! $profile)
    <p class="guide-notice">Tell us what you want to follow to receive explained suggestions.</p><a class="dashboard-button" href="{{ route('markets.suggestions') }}">Help me choose markets</a>
@else
    <p class="guide-help">{{ $exchange?->name }} · Activity ranks otherwise comparable preference matches. Activity is coin-wide and does not establish exchange liquidity or a BUY signal.</p>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 mt-4">
    @forelse ($results['items'] ?? [] as $item)
        <article class="dashboard-market"><div class="flex flex-wrap justify-between gap-2"><h3>{{ $item['symbol'] }}</h3><span class="guide-badge">{{ $item['explore'] ? 'Explore only' : 'Matches preference screens' }}</span></div>
            <ul class="list-disc pl-5 mt-3">@foreach ($item['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
            @if ($item['activity'])<p class="guide-notice mt-3">24h volume / market cap: {{ \App\Helpers\Decimal::format($item['activity']['activity_ratio'] * 100, 1) }}% · 24h price change: {{ $item['activity']['change_24h'] === null ? 'Unknown' : \App\Helpers\Decimal::format($item['activity']['change_24h'], 2).'%' }}.</p><p class="guide-help">CoinGecko · <x-display-time :value="$item['activity_observed_at_ms']" unit="milliseconds" precision="minutes" /></p>
            @else<p class="guide-help">Current activity context is unavailable for this asset. Preference screens still apply.</p>@endif
            <p class="mt-3">{{ $item['evidence']['message'] }}</p>
            <p class="guide-help">{{ $item['evidence']['known'] ? 'Stored price evidence is available. Model readiness is checked separately after subscribing.' : 'Following this market can start collection; usable intelligence may take time.' }}</p>
            <div class="flex flex-wrap items-center gap-3 mt-4"><a class="dashboard-button" href="{{ route('markets.suggestions.review', ['exchange' => $exchange->class, 'symbol' => $item['symbol'], 'discovery' => 1]) }}">Review market</a>
                <form method="POST" action="{{ route('dashboard.suggestions.dismiss') }}">@csrf<input type="hidden" name="exchange" value="{{ $exchange->class }}"><input type="hidden" name="symbol" value="{{ $item['symbol'] }}"><button type="submit" class="dashboard-link">Hide for 30 days</button></form></div>
        </article>
    @empty<p>No additional markets match the available screens. You can edit your preferences or browse markets directly.</p>@endforelse
    </div>
    @foreach ($results['notes'] ?? [] as $note)<p class="guide-help mt-3">{{ $note }}</p>@endforeach
    <form method="POST" action="{{ route('dashboard.suggestions.restore') }}" class="mt-4">@csrf @method('DELETE')<button class="dashboard-link" type="submit">Restore dismissed suggestions</button></form>
@endif
