<?php

namespace App\Repositories;

use App\Models\Exchange;
use App\Models\Ticker;
use App\Repositories\TickerRepository;
use ccxt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use JasonGuru\LaravelMakeRepository\Repository\BaseRepository;
//use function Trademinator\Ticker\normalize;
use function Trademinator\Time\periods_to_seconds;
use function Trademinator\Time\to_unixtime;
/**
 * Class ExchangeRepository.
 */
class ExchangeRepository extends BaseRepository
{
    protected ?Exchange $exchange;
    protected ?\ccxt\Exchange $ccxtExchange;
    protected TickerRepository $tickerRepository;

    public function __construct(?Exchange $exchange = null)
    {
	parent::__construct();
        $this->exchange = $exchange;
        if (!is_null($exchange))
        {
            $this->setExchange($exchange);
        }
        else
        {
            $this->ccxtExchange = null;
        }

        $this->tickerRepository = new TickerRepository;
    }

    public function describe(): array
    {
        return $this->ccxtExchange->describe();
    }

    public function fetch(string $symbol, string $period, int $from, int $to):array
    {
        $answer = [];
        if(App::hasDebugModeEnabled()){
            Log::debug("public function fetch(string $symbol, string $period, int $from, int $to):array");
        }
        $params = [];
        $startFetching = $from * 1000;   // Must be miliseconds
        $endFetching = $to * 1000;
        switch ($this->exchange->class)
        {
            case 'coinbase':
                $records = 250;
                $offset = periods_to_seconds($period) * $records;
                $params['end'] = $startFetching + $offset * 1000;
                break;
            default:
                $records = 1000;
                $offset = periods_to_seconds($period);
        }

        if(App::hasDebugModeEnabled()){
            Log::debug("startFetching $startFetching; records $records; offset $offset; params ".print_r($params,true));
        }

        while ($startFetching <= $endFetching && count($ohlcv = $this->tickerRepository->fetch($symbol, $period, $startFetching, $records, $params)) > 0)
        {
            $t2 = end($ohlcv)['microtimestamp'];
            foreach ($ohlcv as $candle) {
                if ($candle['microtimestamp'] >= $from * 1000 && $candle['microtimestamp'] <= $endFetching) {
                    $answer[$candle['microtimestamp']] = $candle;
                }
            }
            if ($t2 < $startFetching) {
                break; // The exchange returned no new candles.
            }
            $startFetching = $t2 + periods_to_seconds($period) * 1000;

            if ($startFetching >= $endFetching)
            {
                if(App::hasDebugModeEnabled()){
                    Log::debug("startFetching $startFetching >= endFetching $endFetching");
                }
                break;
            }

            switch ($this->exchange->class)
            {
                case 'coinbase':
                    $params['end'] = $startFetching + $offset * 1000;
                    break;
                default:
                    break;
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
        return in_array($className, \ccxt\Exchange::$exchanges);
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
     *  Return the model
     */
    public function model():string
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
        $ccxtExchangeName = '\\ccxt\\' . $exchange->class;
        // DB saves the confing in JSON format, but CCXT expects it in an associative array
        $settings = json_decode($this->exchange->config ?: '{}', true) ?: [];
        $settings['enableRateLimit'] = true;
        if (count($extraSettings))
        {
            $settings = array_merge($settings, $extraSettings);
        }
        $this->ccxtExchange = new $ccxtExchangeName($settings);
        $this->tickerRepository->setExchange($this->ccxtExchange);
    }
}
