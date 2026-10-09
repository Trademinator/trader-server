@props(['ariaLabel' => 'Candle training'])
<x-market-candlestick {{ $attributes }} :aria-label="$ariaLabel"
    canvas-class="review-chart" :show-status="false" :show-legend="false">
    {{ $slot }}
</x-market-candlestick>
