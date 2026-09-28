<?php

namespace App\Domain\MarketData;

use App\Traits\TickerManipulation;

/** Compatibility adapter; the shareable trait owns all OHLCV normalization. */
final class OhlcvNormalizer
{
    use TickerManipulation;

    public function normalize(array $candles, bool $reindex = false, string $indexUnit = 'seconds'): array
    {
        return $this->normalize_ticker($candles, $reindex, $indexUnit);
    }
}
