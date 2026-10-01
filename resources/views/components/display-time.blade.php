@if ($instant)
    <time {{ $attributes }} datetime="{{ $instant->toISOString() }}" data-display-time data-time-precision="{{ $precision }}">{{ $text }}</time>
@else
    {{ $fallback }}
@endif
