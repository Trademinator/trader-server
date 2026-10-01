<?php

namespace App\Repositories;

use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\ExchangeCredentials;
use App\Domain\MarketData\MarketCatalogException;
use App\Models\Exchange;
use App\Models\User;
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

    private ?string $clientConfiguration = null;

    private ?array $candleOptions = null;

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
            // Coinbase requires a bounded end time. CCXT expects `until` in
            // milliseconds and converts it to Coinbase's UNIX-seconds `end`.
            // Keep the full request window, matching the legacy collector;
            // returned candles are filtered to $endFetching below.
            $params['until'] = $startFetching + $batchWindowMilliseconds;
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
                    $params['until'] = $startFetching + $batchWindowMilliseconds;
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
                    $params['until'] = $startFetching + $batchWindowMilliseconds;
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
                $params['until'] = $startFetching + $batchWindowMilliseconds;
            }
        }

        ksort($answer, SORT_NUMERIC);
        $ohlcv = array_values($answer);
        unset($answer);
        $this->tickerRepository->saveTickers($this->exchange->class, $symbol, $period, $ohlcv);

        return $ohlcv;
    }

    /** One bounded CCXT page, without persistence; history checkpoints own the transaction. */
    public function fetchHistoryPage(string $symbol, string $period, int $fromMs, int $untilMs, int $limit): array
    {
        if ($fromMs < 0 || $fromMs >= $untilMs || $limit < 1 || $limit > 100
            || ! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            throw new \InvalidArgumentException('Invalid historical candle page.');
        }

        // The history worker gives us an exclusive upper bound. Keep the CCXT
        // request inside that window instead of blindly using the configured
        // page size. Some adapters, including NDAX, derive their API ToDate
        // from `since + limit * timeframe`.
        $timeframe = new CandleTimeframe;
        $requestLimit = 0;
        for ($cursor = $fromMs; $cursor < $untilMs && $requestLimit < $limit; $requestLimit++) {
            $next = $timeframe->next($cursor, $period);
            if ($next <= $cursor) {
                throw new \RuntimeException('Historical candle timeframe did not advance.');
            }
            $cursor = $next;
        }

        if ($requestLimit < 1) {
            throw new \InvalidArgumentException('Historical candle page contains no request interval.');
        }

        $params = match ($this->exchange->class) {
            'coinbase' => ['until' => $untilMs],
            'bitso' => ['end' => $untilMs],
            'ndax' => ['ToDate' => ccxt\Exchange::ymdhms($untilMs)],
            default => [],
        };
        $this->ccxtExchange->options['paginate'] = false;
        if (is_array($this->ccxtExchange->options['fetchOHLCV'] ?? null)) {
            $this->ccxtExchange->options['fetchOHLCV']['paginate'] = false;
        }

        return $this->tickerRepository->fetch($symbol, $period, $fromMs, $requestLimit, $params);
    }

    /** Confirm that an adapter returned at least one candle inside an exact historical window. */
    public function hasHistoricalData(string $symbol, string $period, int $fromMs, int $untilMs, int $limit): bool
    {
        foreach ($this->fetchHistoryPage($symbol, $period, $fromMs, $untilMs, $limit) as $candle) {
            $timestamp = (int) ($candle['microtimestamp'] ?? -1);
            if ($timestamp >= $fromMs && $timestamp < $untilMs) {
                return true;
            }
        }

        return false;
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
        try {
            return $this->ccxtExchange->load_markets();
        } catch (\Throwable $error) {
            throw ExchangeCredentials::safeFailure($error);
        }
    }

    /** Keep only the requested instrument in CCXT's market/currency indexes. */
    public function prepareCandleMarket(string $symbol): void
    {
        $client = $this->ccxtExchange;
        if (! isset($client->markets[$symbol]) && $this->candleOptions !== null) {
            $client->options = $this->candleOptions;
        }
        $client->options['paginate'] = false;
        if (is_array($client->options['fetchOHLCV'] ?? null)) {
            $client->options['fetchOHLCV']['paginate'] = false;
        }
        if (isset($client->markets[$symbol])) {
            return;
        }
        try {
            $rows = ! str_contains($symbol, ':') && ($client->has['spot'] ?? false)
                ? $this->spotMarketRows($this->spotParameters()) : $client->fetch_markets();
            foreach ($rows as $market) {
                if (($market['symbol'] ?? null) === $symbol) {
                    $client->set_markets([$market]);

                    return;
                }
            }
        } catch (\Throwable $error) {
            throw ExchangeCredentials::safeFailure($error);
        } finally {
            $client->last_http_response = null;
            $client->last_json_response = null;
        }
        throw new \InvalidArgumentException('The exchange does not support this symbol.');
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
        $result = [];
        foreach ($this->spotMarketRows($this->spotParameters()) as $market) {
            $symbol = $market['symbol'] ?? null;
            if (is_string($symbol) && ($market['spot'] ?? ($market['type'] ?? null) === 'spot') === true) {
                $result[$symbol] = ['spot' => true, 'precision' => $market['precision'] ?? [],
                    'active' => $market['active'] ?? null, 'limits' => $market['limits'] ?? [],
                    'taker' => $market['taker'] ?? null];
            }
        }

        return $result;
    }

    private function spotParameters(): array
    {
        $client = $this->ccxtExchange;
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

        return $params;
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
        } catch (\Throwable $error) {
            throw ExchangeCredentials::safeFailure($error);
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
        return $this->ccxtExchange->timeframes ?? [];
    }

    protected function newClient(string $class, #[\SensitiveParameter] array $settings): ccxt\Exchange
    {
        try {
            return new $class($settings);
        } catch (\Throwable $error) {
            throw ExchangeCredentials::safeFailure($error);
        }
    }

    public function setExchange(Exchange $exchange, #[\SensitiveParameter] array $extraSettings = [], ?User $user = null, ?string $symbol = null): void
    {
        $this->exchange = $exchange;
        $ccxtExchangeName = '\\ccxt\\'.$exchange->class;
        // DB saves the confing in JSON format, but CCXT expects it in an associative array
        $settings = app(ExchangeCredentials::class)->settings($exchange, $user ?? ($symbol === null ? auth()->user() : null), $symbol);
        if (count($extraSettings)) {
            $settings = array_merge($settings, $extraSettings);
        }
        if (array_filter(array_intersect_key($settings, ExchangeCredentials::FIELDS))) {
            $settings['verbose'] = false;
            $settings['options']['fetchCurrencies'] = false;
            $settings['has']['fetchCurrencies'] = false;
            $settings['options']['fetchMargins'] = false;
        }
        if ($exchange->class === 'coinbase' && ! empty($settings['apiKey']) && ! empty($settings['secret'])) {
            $settings['options']['usePrivate'] = true;
            $settings['options']['fetchOHLCV']['usePrivate'] = true;
        }
        $settings['enableRateLimit'] = true;
        $settings['enableLastHttpResponse'] = false;
        $settings['enableLastJsonResponse'] = false;
        $configuration = hash('sha256', $exchange->class.json_encode($settings, JSON_THROW_ON_ERROR));
        if ($this->clientConfiguration === $configuration) {
            return;
        }
        $this->tickerRepository->setExchange(null);
        $this->ccxtExchange = null;
        gc_collect_cycles();
        $this->ccxtExchange = $this->newClient($ccxtExchangeName, $settings);
        $this->clientConfiguration = $configuration;
        $this->candleOptions = $this->ccxtExchange->options;
        $this->tickerRepository->setExchange($this->ccxtExchange);
    }
}
