<x-layouts.app :title="'Review '.$item['symbol']">
    @include('markets.guide-styles')
    @include('markets.review-styles')
    @php
        $number = static function ($value) {
            if ($value === null) { return 'Unknown'; }
            if ((float) $value === 0.0) { return '0'; }
            $decimals = min(18, max(1, 8 - (int) floor(log10(abs((float) $value)))));
            return rtrim(rtrim(number_format((float) $value, $decimals, '.', ','), '0'), '.');
        };
    @endphp
    <section class="pair-guide pair-review">
        <nav class="review-topline" aria-label="Pair review navigation">
            <a href="{{ route('markets.index') }}">← Your markets</a>
            <a href="{{ route('markets.suggestions') }}">Pair preferences</a>
        </nav>
        @if ($errors->any())
            <div class="guide-notice guide-error" role="alert"><strong>Could not subscribe.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <header class="guide-hero">
            <p class="review-eyebrow">{{ $exchange->name }} · {{ $item['subscribed'] ? 'Subscribed market' : 'Available market' }}</p>
            <h1 class="review-title">Review {{ $item['symbol'] }}</h1>
            <p>{{ $item['base'] }} is the base asset. Prices show how much {{ $item['quote'] }} buys one {{ $item['base'] }}.</p>
        </header>
        <section class="guide-panel">
            <h2>Technical review</h2>
            <p class="guide-notice">{{ $notice }}</p>
            <p>This page shows stored market data. It does not establish a personal preference match or recommend a trade.</p>
            <dl>
                <dt>Subscription</dt><dd>{{ $item['subscribed'] ? 'Active' : 'Not subscribed' }}</dd>
                <dt>Shared feed</dt><dd>{{ $feed?->status ?? 'pending' }}</dd>
                <dt>Selected candle period</dt><dd>{{ $feed?->selected_period ?? 'Selecting automatically' }}</dd>
                <dt>Stored price increment</dt><dd>{{ $number($market['tick_size']) }} {{ $item['quote'] }}</dd>
            </dl>
            <p class="guide-help">Price increments come from the stored market record. Exchange availability and current trading limits have not been verified here.</p>
            @if (! $item['subscribed'])
                <form method="POST" action="{{ route('markets.store') }}" class="mt-4">
                    @csrf
                    <input type="hidden" name="exchange" value="{{ $exchange->class }}">
                    <input type="hidden" name="symbol" value="{{ $item['symbol'] }}">
                    <button type="submit" class="guide-button">Subscribe to {{ $item['symbol'] }}</button>
                </form>
                <p class="guide-help">Subscribing starts or resumes this market's shared collection. It does not place a trade.</p>
            @endif
            <p><a href="{{ route('markets.suggestions') }}">Review your preferences and current suggestions</a></p>
        </section>
        @include('markets.review-chart', ['technicalReview' => true])
    </section>
</x-layouts.app>
