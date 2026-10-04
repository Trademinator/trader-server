<?php

namespace App\Domain\MarketData;

use Trademinator\Indicators\Traits\TickerManipulation;

/** Application adapter; the published package owns OHLCV normalization. */
final class OhlcvNormalizer
{
    use TickerManipulation;

    public function normalize(array $candles, bool $reindex = false, string $indexUnit = 'seconds'): array
    {
        return $this->normalize_ticker($candles, $reindex, $indexUnit);
    }
}
