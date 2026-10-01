<select {{ $formControlAttributes }} {{ $attributes }}>
    @if($placeholder !== '')
        <option value="" disabled @selected($isSelected(''))>{{ $placeholder }}</option>
    @endif
    @forelse($options as $option)
        @php($disabled = $option['disabled'] ?? false)
        <option value="{{ $option['value'] }}" @selected(! $disabled && $isSelected($option['value'])) @disabled($disabled)
            @if(($option['status'] ?? null) === 'subscribed') style="color:#15803d;opacity:1;" @endif>{{ $option['label'] }}</option>
    @empty
        <option value="" disabled selected>No subscribed pairs available</option>
    @endforelse
</select>
