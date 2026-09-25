<?php

namespace Trademinator\Ticker;

use App\Domain\MarketData\OhlcvNormalizer;

if (! function_exists(__NAMESPACE__.'\\normalize')) {
    function normalize(array &$tickers, bool $reindex = false): array
    {
        $tickers = (new OhlcvNormalizer)->normalize($tickers, $reindex);

        return $tickers;
    }
}
