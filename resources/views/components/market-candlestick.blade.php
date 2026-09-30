@props([
    'ariaLabel' => 'Candlestick chart',
    'canvasClass' => 'dashboard-chart',
    'status' => 'Loading chart…',
    'legend' => '',
    'showStatus' => true,
    'showLegend' => true,
    'refresh' => false,
    'fit' => false,
    'earliest' => false,
    'serverSignals' => false,
    'humanTraining' => false,
    'autoRefresh' => false,
])

<div {{ $attributes }}>
    @if ($refresh || $fit || $earliest || $serverSignals || $humanTraining || $autoRefresh)
        <div class="flex flex-wrap items-center gap-4 my-3">
            @if ($refresh)<button type="button" class="dashboard-button" data-refresh>Refresh chart</button>@endif
            @if ($fit)<button type="button" class="dashboard-button" data-fit>Fit candles</button>@endif
            @if ($earliest)<button type="button" class="dashboard-button" data-earliest>Earliest data</button>@endif
            @if ($serverSignals)<label><input type="checkbox" data-markers checked> Show Server signals</label>@endif
            @if ($humanTraining)<label><input type="checkbox" data-human-training> Show human training</label>@endif
            @if ($autoRefresh)<label><input type="checkbox" data-auto checked> Refresh every minute</label>@endif
        </div>
    @endif

    @if ($showStatus)<p class="guide-help" role="status" aria-live="polite" data-status>{{ $status }}</p>@endif
    @if ($showLegend)<p class="guide-help" data-legend>{{ $legend }}</p>@endif

    <div class="{{ $canvasClass }}" data-canvas role="img" aria-label="{{ $ariaLabel }}"></div>
    {{ $slot }}

    @if ($earliest)
        <p class="guide-help" data-history-status role="status" aria-live="polite"></p>
        <button type="button" class="dashboard-button" data-history-retry hidden>Retry loading candles</button>
    @endif
</div>
