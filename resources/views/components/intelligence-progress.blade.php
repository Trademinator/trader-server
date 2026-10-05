@props(['label', 'value', 'target', 'native' => false, 'decimals' => 0, 'suffix' => ''])
@php
    $maximum = max(1, (float) $target);
    $bounded = max(0, min((float) $value, $maximum));
    $percent = min(100, max(0, (float) $value / $maximum * 100));
@endphp
<div class="intelligence-progress">
    <div class="intelligence-progress-label">
        <span>{{ $label }}</span>
        <strong>{{ \App\Helpers\Decimal::format($value, $decimals) }}{{ $suffix }} out of {{ \App\Helpers\Decimal::format($target, $decimals) }}{{ $suffix }}</strong>
    </div>
    @if ($native)
        <progress class="intelligence-native-progress" value="{{ $bounded }}" max="{{ $maximum }}" aria-label="{{ $label }}">{{ \App\Helpers\Decimal::format($percent, 1) }}%</progress>
    @else
        <div class="progress" role="progressbar" aria-label="{{ $label }}" aria-valuenow="{{ $bounded }}" aria-valuemin="0" aria-valuemax="{{ $maximum }}">
            <div class="progress-bar {{ $value >= $target ? 'bg-success' : '' }}" style="width: {{ $percent }}%"></div>
        </div>
    @endif
</div>
