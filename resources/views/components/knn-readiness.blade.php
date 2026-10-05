<span {{ $attributes->class(['knn-readiness', 'knn-readiness-totals' => $counts !== null]) }} role="group" aria-label="Intelligence readiness">
    @foreach ($indicators as $name => $indicator)
        <span @class(['knn-readiness-item', 'knn-readiness-'.$name => $indicator['ready'], 'knn-readiness-pending' => ! $indicator['ready']])
            role="img" aria-label="{{ $indicator['description'] }}" title="{{ $indicator['description'] }}">
            <span class="knn-readiness-icon" aria-hidden="true">{{ $indicator['ready'] ? '✓' : '○' }}</span>
            <span aria-hidden="true">{{ $indicator['label'] }}@if ($counts !== null)<span class="knn-readiness-count">{{ \App\Helpers\Decimal::format($indicator['count']) }} / {{ \App\Helpers\Decimal::format($total) }}</span>@endif</span>
        </span>
    @endforeach
</span>
