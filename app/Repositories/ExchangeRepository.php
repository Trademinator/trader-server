<?php

namespace App\Repositories;

use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\MarketCatalogException;
use App\Models\Exchange;
use ccxt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use JasonGuru\LaravelMakeRepository\Repository\BaseRepository;

// use function Trademinator\Ticker\normalize;
use function Trademinator\Time\periods_to_seconds;

/**
 * Class ExchangeRepository.
 */
class ExchangeRepository extends BaseRepository
{
    protected ?Exchange $exchange;

    protected ?ccxt\Exchange $ccxtExchange;

    protected TickerRepository $tickerRepository;

    public function __construct(?Exchange $exchange = null)
    {
        parent::__construct();
        $this->tickerRepository = new TickerRepository;
        $this->exchange = $exchange;
        if (! is_null($exchange)) {
            $this->setExchange($exchange);
        } else {
            $this->ccxtExchange = null;
        }
    }

    public function describe(): array
    {
        return $this->ccxtExchange->describe();
    }

    public function fetch(string $symbol, string $period, int $from, int $to, int $limit = 100): array
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('The fetch start time must be before or equal to the end time.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('OHLCV request limit must be between 1 and 100 candles.');
        }

        if (! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            throw new \InvalidArgumentException("Unsupported candle period: {$period}");
        }
        $periodMilliseconds = periods_to_seconds($period) * 1000;

        $answer = [];
        if (App::hasDebugModeEnabled()) {
            Log::debug("public function fetch(string $symbol, string $period, int $from, int $to):array");
        }

        $params = [];
        $startFetching = $from * 1000;
        $endFetching = $to * 1000;

        $records = $limit;
        $batchWindowMilliseconds = $periodMilliseconds * $records;
        if ($this->exchange->class === 'coinbase') {
            $params['end'] = min($endFetching, $startFetching + $batchWindowMilliseconds);
        }

        if (App::hasDebugModeEnabled()) {
            Log::debug("startFetching $startFetching; records $records; params ".print_r($params, true));
        }

        $timeframe = new CandleTimeframe;
        while ($startFetching <= $endFetching) {
            $ohlcv = $this->tickerRepository->fetch($symbol, $period, $startFetching, $records, $params);
            if (count($ohlcv) === 0) {
                // A quiet interval can be empty even when a later interval
                // contains trades. Advance by one bounded request window.
                $startFetching += $batchWindowMilliseconds;
                if ($this->exchange->class === 'coinbase') {
                    $params['end'] = min($endFetching, $startFetching + $batchWindowMilliseconds);
                }

                continue;
            }

            $lastTimestamp = max(array_map(fn (array $candle): int => (int) $candle['microtimestamp'], $ohlcv));

            foreach ($ohlcv as $candle) {
                $timestamp = (int) $candle['microtimestamp'];
                if ($timestamp >= $from * 1000 && $timestamp <= $endFetching) {
                    $answer[$timestamp] = $candle;
                }
            }

            if ($lastTimestamp < $startFetching) {
                $startFetching += $batchWindowMilliseconds;
                if ($this->exchange->class === 'coinbase') {
                    $params['end'] = min($endFetching, $startFetching + $batchWindowMilliseconds);
                }

                continue;
            }

            $nextStart = $timeframe->next($lastTimestamp, $period);
            if ($nextStart <= $startFetching) {
                break;
            }

            $startFetching = $nextStart;

            // Important: equality is still a valid request. The old >= check
            // skipped a candle that landed exactly on the requested end time.
            if ($startFetching > $endFetching) {
                if (App::hasDebugModeEnabled()) {
                    Log::debug("startFetching $startFetching > endFetching $endFetching");
                }
                break;
            }

            if ($this->exchange->class === 'coinbase') {
                $params['end'] = min($endFetching, $startFetching + $batchWindowMilliseconds);
            }
        }

        ksort($answer, SORT_NUMERIC);
        $ohlcv = array_values($answer);
        unset($answer);
        $this->tickerRepository->saveTickers($this->exchange->class, $symbol, $period, $ohlcv);

        return $ohlcv;
    }

    public function findById(string $exchange_id): ?Collection
    {
        $exchange_q = Exchange::where('exchange_id', $exchange_id);

        return $exchange = $exchange_q->get();
    }

    public function findByName(string $name): ?Collection
    {
        $exchange_q = Exchange::where('name', $name);

        return $exchange = $exchange_q->get();
    }

    public function findByClass(string $class): ?Collection
    {
        $exchange_q = Exchange::where('class', $class);

        return $exchange = $exchange_q->get();
    }

    public function hasExchange(string $className): bool
    {
        return in_array($className, ccxt\Exchange::$exchanges);
    }

    public function hasMarket(string $market): bool
    {
        return in_array($market, array_keys($this->markets()));
    }

    public function hasPeriods(string $period): bool
    {
        return in_array($period, array_keys($this->periods()));
    }

    public function markets(): array
    {
        return $this->ccxtExchange->load_markets();
    }

    /** Read-only spot metadata, without load_markets' currency and market indexes. */
    public function spotMarkets(): array
    {
        $client = $this->ccxtExchange;
        $description = $client->describe();
        if (($description['has']['spot'] ?? false) !== true) {
            throw new MarketCatalogException('spot_unsupported',
                'This exchange adapter does not offer spot pairs. This page currently supports spot markets only.', 422);
        }
        if (! ($description['has']['fetchOHLCV'] ?? false)) {
            throw new MarketCatalogException('candles_unsupported',
                'This exchange adapter does not offer the candle data required by this page.', 422);
        }
        // These settings affect this catalogue client only; persisted credentials,
        // custom endpoints and the general CLI/collector market loader are retained.
        $client->options['defaultType'] = 'spot';
        $client->options['fetchCurrencies'] = false;
        $client->options['loadAllOptions'] = false;
        $types = $client->options['fetchMarkets']['types'] ?? null;
        if (is_array($types)) {
            foreach (['spot', 'SPOT'] as $spot) {
                if (in_array($spot, $types, true)) {
                    $client->options['fetchMarkets']['types'] = [$spot];
                    break;
                }
            }
        }
        $params = [];
        if ($client instanceof ccxt\binance) {
            $client->options['fetchMarkets'] = ['types' => ['spot']];
            $client->options['fetchMargins'] = false;
            // Large permission sets are irrelevant to a read-only pair catalogue.
            $params['showPermissionSets'] = false;
        }
        $result = [];
        foreach ($this->spotMarketRows($params) as $market) {
            $symbol = $market['symbol'] ?? null;
            if (is_string($symbol) && ($market['spot'] ?? ($market['type'] ?? null) === 'spot') === true) {
                $result[$symbol] = ['spot' => true, 'precision' => $market['precision'] ?? [],
                    'active' => $market['active'] ?? null, 'limits' => $market['limits'] ?? [],
                    'taker' => $market['taker'] ?? null];
            }
        }

        return $result;
    }

    private function spotMarketRows(array $params): iterable
    {
        $client = $this->ccxtExchange;
        try {
            if ($client instanceof ccxt\binance) {
                // CCXT fetch_markets retains thousands of full normalized market
                // objects alongside the decoded response. Normalize one at a time
                // with CCXT's own parser, retaining only the compact result above.
                $response = $client->publicGetExchangeInfo($params);
                if (! isset($response['symbols']) || ! is_array($response['symbols'])) {
                    throw new \UnexpectedValueException('Missing spot symbols in exchange response.');
                }
                $client->options['crossMarginPairsData'] = [];
                $client->options['isolatedMarginPairsData'] = [];
                $client->last_http_response = null;
                $client->last_json_response = null;
                foreach ($response['symbols'] as $market) {
                    yield $client->parse_market($market);
                }
            } else {
                yield from $client->fetch_markets($params);
            }
        } finally {
            $client->last_http_response = null;
            $client->last_json_response = null;
        }
    }

    /**
     * @return string
     *                Return the model
     */
    public function model(): string
    {
        return Exchange::class;
    }

    public function periods(): array
    {
        return $this->describe()['timeframes'] ?? [];
    }

    public function setExchange(Exchange $exchange, array $extraSettings = [])
    {
        $this->exchange = $exchange;
        $ccxtExchangeName = '\\ccxt\\'.$exchange->class;
        // DB saves the confing in JSON format, but CCXT expects it in an associative array
        $settings = json_decode($this->exchange->config ?: '{}', true) ?: [];
        if (count($extraSettings)) {
            $settings = array_merge($settings, $extraSettings);
        }
        $settings['enableRateLimit'] = true;
        $this->ccxtExchange = new $ccxtExchangeName($settings);
        $this->tickerRepository->setExchange($this->ccxtExchange);
    }
}
