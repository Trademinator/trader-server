<?php

namespace App\Traits;

use Trademinator\Indicators\Traits\TickerManipulation;

trait Indexing
{
    use TickerManipulation;

    public function normalize(array &$tickers, bool $reindex = false): array
    {
        $this->normalize_ticker($tickers, $reindex);

        return $tickers;
    }
}
