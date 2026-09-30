<select {{ $formControlAttributes }} {{ $attributes }}>
    @if($placeholder !== '')
        <option value="" disabled @selected($isSelected(''))>{{ $placeholder }}</option>
    @endif
    @forelse($options as $option)
        <option value="{{ $option['value'] }}" @selected($isSelected($option['value']))>{{ $option['label'] }}</option>
    @empty
        <option value="" disabled selected>No subscribed pairs available</option>
    @endforelse
</select>
