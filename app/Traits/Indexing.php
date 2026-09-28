<?php

namespace App\Traits;

trait Indexing
{
    use TickerManipulation;

    public function normalize(array &$tickers, bool $reindex = false): array
    {
        $this->normalize_ticker($tickers, $reindex);

        return $tickers;
    }
}
