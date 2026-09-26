<?php

namespace App\Domain\Features;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CoinGeckoClient
{
    public function get(string $path, array $query = []): array
    {
        $pro = (bool) config('features.coingecko.pro');
        $key = config('features.coingecko.api_key');
        $response = Http::baseUrl($pro ? 'https://pro-api.coingecko.com/api/v3' : 'https://api.coingecko.com/api/v3')
            ->acceptJson()
            ->withHeaders([$pro ? 'x-cg-pro-api-key' : 'x-cg-demo-api-key' => $key])
            ->connectTimeout(10)
            ->timeout(30)
            ->get($path, $query)
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('Invalid CoinGecko response.');
        }

        return $response;
    }
}
