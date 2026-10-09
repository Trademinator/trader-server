@props(['ariaLabel' => 'Historical Server decisions'])
<x-market-candlestick {{ $attributes }} :aria-label="$ariaLabel"
    :refresh="true" :fit="true" :earliest="true" :server-signals="true"
    :action-decisions="true" :outcome-decisions="true"
    :client-activity="true" :auto-refresh="true">
    {{ $slot }}
</x-market-candlestick>
