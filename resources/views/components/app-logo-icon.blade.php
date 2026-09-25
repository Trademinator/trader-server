@props(['variant' => 'icon', 'alt' => ''])

@php
    $customLogo = trim((string) config('app.logo_url'));
    $fullLogo = $variant === 'full';
    $logoPath = $customLogo !== ''
        ? $customLogo
        : 'images/branding/'.($fullLogo ? 'trademinator-logo.webp' : 'trademinator-icon.webp');
@endphp

<img
    src="{{ asset($logoPath) }}"
    alt="{{ $alt }}"
    @if ($alt === '') aria-hidden="true" @endif
    decoding="async"
    {{ $attributes->class(['block object-contain'])->merge([
        'width' => $fullLogo ? 960 : 256,
        'height' => $fullLogo ? 960 : 256,
    ]) }}
>
