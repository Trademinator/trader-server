<?php

namespace Tests\Support;

use ccxt\binance;
use RuntimeException;

/** Real CCXT signing/JSON decoding/market parsing, with a local HTTP response. */
class FixtureBinance extends binance
{
    public static string $responsePath;

    public static int $requests = 0;

    public function fetch($url, $method = 'GET', $headers = null, $body = null)
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if (parse_url($url, PHP_URL_PATH) !== '/api/v3/exchangeInfo'
            || ! in_array($query['showPermissionSets'] ?? null, ['false', '0'], true)) {
            throw new RuntimeException('Unexpected endpoint or oversized permission response requested.');
        }
        self::$requests++;
        // Match CCXT's cURL path, which retains headers+body while decoding a
        // separate body string; omitting that copy understates peak memory.
        $headers = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n";
        $response = $headers.file_get_contents(self::$responsePath);
        $this->last_http_response = mb_substr($response, strlen($headers));
        $this->last_json_response = $this->parse_json($this->last_http_response);

        return $this->last_json_response;
    }

    public static function writeResponse(string $path, int $count): void
    {
        $stream = fopen($path, 'wb');
        fwrite($stream, '{"timezone":"UTC","serverTime":1790000000000,"rateLimits":[],"exchangeFilters":[],"symbols":[');
        for ($i = 0; $i < $count; $i++) {
            $base = $i === 0 ? 'BTC' : 'COIN'.$i;
            $market = [
                'symbol' => $base.'USDT', 'status' => 'TRADING', 'baseAsset' => $base,
                'baseAssetPrecision' => 8, 'quoteAsset' => 'USDT', 'quotePrecision' => 8,
                'quoteAssetPrecision' => 8, 'baseCommissionPrecision' => 8, 'quoteCommissionPrecision' => 8,
                'orderTypes' => ['LIMIT', 'LIMIT_MAKER', 'MARKET', 'STOP_LOSS', 'STOP_LOSS_LIMIT', 'TAKE_PROFIT', 'TAKE_PROFIT_LIMIT'],
                'icebergAllowed' => true, 'ocoAllowed' => true, 'otoAllowed' => true,
                'quoteOrderQtyMarketAllowed' => true, 'allowTrailingStop' => true,
                'cancelReplaceAllowed' => true, 'isSpotTradingAllowed' => true, 'isMarginTradingAllowed' => true,
                'permissions' => [], 'permissionSets' => [],
                'defaultSelfTradePreventionMode' => 'EXPIRE_MAKER',
                'allowedSelfTradePreventionModes' => ['EXPIRE_TAKER', 'EXPIRE_MAKER', 'EXPIRE_BOTH'],
                'filters' => [
                    ['filterType' => 'PRICE_FILTER', 'minPrice' => '0.00000001', 'maxPrice' => '1000000', 'tickSize' => '0.01000000'],
                    ['filterType' => 'LOT_SIZE', 'minQty' => '0.00001', 'maxQty' => '9000', 'stepSize' => '0.00001'],
                    ['filterType' => 'ICEBERG_PARTS', 'limit' => 10],
                    ['filterType' => 'MARKET_LOT_SIZE', 'minQty' => '0', 'maxQty' => '1000', 'stepSize' => '0'],
                    ['filterType' => 'TRAILING_DELTA', 'minTrailingAboveDelta' => 10, 'maxTrailingAboveDelta' => 2000, 'minTrailingBelowDelta' => 10, 'maxTrailingBelowDelta' => 2000],
                    ['filterType' => 'PERCENT_PRICE_BY_SIDE', 'bidMultiplierUp' => '5', 'bidMultiplierDown' => '0.2', 'askMultiplierUp' => '5', 'askMultiplierDown' => '0.2', 'avgPriceMins' => 5],
                    ['filterType' => 'NOTIONAL', 'minNotional' => '5', 'applyMinToMarket' => true, 'maxNotional' => '9000000', 'applyMaxToMarket' => false, 'avgPriceMins' => 5],
                    ['filterType' => 'MAX_NUM_ORDERS', 'maxNumOrders' => 200],
                    ['filterType' => 'MAX_NUM_ALGO_ORDERS', 'maxNumAlgoOrders' => 5],
                ],
            ];
            fwrite($stream, ($i === 0 ? '' : ',').json_encode($market, JSON_THROW_ON_ERROR));
        }
        fwrite($stream, ']}');
        fclose($stream);
    }
}
