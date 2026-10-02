<?php

it('keeps application OHLCV payload reads behind streamHistory', function () {
    $root = dirname(__DIR__, 2);
    $paths = [
        $root.'/app/Domain/MarketData/MarketChart.php',
        $root.'/app/Domain/MarketSuggestions/CandleEvidence.php',
        $root.'/app/Domain/Research/DatasetSnapshotBuilder.php',
        $root.'/app/Domain/Intelligence/MarketIntelligence.php',
        $root.'/app/Domain/Intelligence/LeadLagSeries.php',
        $root.'/app/Domain/Intelligence/HumanTraining.php',
        $root.'/app/Domain/Features/FeatureBuilder.php',
    ];

    foreach ($paths as $path) {
        $source = (string) file_get_contents($path);
        expect($source)
            ->not->toContain('App\\Models\\Ticker')
            ->not->toContain('Ticker::query()')
            ->toContain('streamHistory(');
    }
});
