<?php

namespace App\Repositories;

use App\Models\Exchange;
use App\Domain\MarketData\CandleTimeframe;
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
        $this->exchange = $exchange;
        if (! is_null($exchange)) {
            $this->setExchange($exchange);
        } else {
            $this->ccxtExchange = null;
        }

        $this->tickerRepository = new TickerRepository;
    }

    public function describe(): array
    {
        return $this->ccxtExchange->describe();
    }

    public function fetch(string $symbol, string $period, int $from, int $to): array
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('The fetch start time must be before or equal to the end time.');
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

        switch ($this->exchange->class) {
            case 'coinbase':
                $records = 250;
                $batchWindowMilliseconds = $periodMilliseconds * $records;
                $params['end'] = min($endFetching, $startFetching + $batchWindowMilliseconds);
                break;
            default:
                $records = 1000;
                $batchWindowMilliseconds = $periodMilliseconds * $records;
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
        return $this->describe()['timeframes'];
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
