<?php

namespace App\Traits;

use App\Domain\MarketData\OhlcvNormalizer;

trait Indexing
{
    public function normalize(array &$tickers, bool $reindex = false): array
    {
        $tickers = (new OhlcvNormalizer)->normalize($tickers, $reindex);

        return $tickers;
    }
}
