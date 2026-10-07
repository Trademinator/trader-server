@props(['title'])
<x-layouts.app :title="$title">
    <div class="owner-page">
        <header class="owner-hero">
            <p class="owner-eyebrow">TRADEMINATOR · SERVER OWNER</p>
            <h1>{{ $title }}</h1>
            <p>Global operations and account management · Times in <x-timezone-label /></p>
        </header>
        @if(session('status'))<div class="owner-notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())
            <div class="owner-notice owner-warning" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        {{ $slot }}
    </div>
</x-layouts.app>
