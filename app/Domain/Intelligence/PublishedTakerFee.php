<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\MarketCatalog;
use App\Models\Exchange;
use Throwable;

/** Read the published exchange taker fee used by the retrospective Action auto-labeler. */
final class PublishedTakerFee
{
    public function __construct(private MarketCatalog $catalog) {}

    public function for(string $exchange, string $symbol): ?float
    {
        // Curated published ceilings are the authoritative conservative source.
        $override = config("exchange_fees.taker_overrides.{$exchange}.rate");
        if (is_numeric($override) && is_finite((float) $override) && (float) $override >= 0) {
            return (float) $override;
        }

        $matches = Exchange::query()->where('class', $exchange)->limit(2)->get();
        if ($matches->count() !== 1) {
            return null;
        }

        try {
            foreach ($this->catalog->forExchange($matches->first())['symbols'] as $market) {
                if (($market['value'] ?? null) !== $symbol) {
                    continue;
                }
                $fee = $market['taker_fee'] ?? null;

                return is_numeric($fee) && is_finite((float) $fee) && (float) $fee >= 0
                    ? (float) $fee : null;
            }
        } catch (Throwable) {
            // A temporary metadata failure must remain explicit to the caller.
        }

        return null;
    }
}
