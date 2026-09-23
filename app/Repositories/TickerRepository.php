<?php

namespace App\Repositories;

use App\Models\Ticker;
use App\Traits\Indexing;
use ccxt;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JasonGuru\LaravelMakeRepository\Repository\BaseRepository;

/**
 * Class MarketRepository.
 */
class TickerRepository extends BaseRepository
{
    use Indexing;
    protected ?Ticker $ticker;
    protected ?\ccxt\Exchange $ccxtExchange;

    public function __construct(?Ticker $ticker = null)
    {
        $this->ticker = $ticker;
    }

    public function fetch(string $symbol, string $period, int $startFetching, int $records, array $params): array
    {
        // TODO: Check if DB has some tickers or not
        $myTickers = $this->ccxtExchange->fetch_ohlcv($symbol, $period, $startFetching, $records, $params);
        if(App::hasDebugModeEnabled()){
            Log::debug("myTickers: ".print_r($myTickers,true));
        }
        $myTickers = $this->normalize($myTickers);
        return $myTickers;
    }

    public function fetchFromDB(string $exchange, string $symbol, string $period, ?int $startFetching = null, ?int $endFetching = null):array
    {
        $tickers_q = Ticker::where('exchange', $exchange)
                    ->where('symbol', $symbol)
                    ->where('period', $period)
                    ->when(!is_null($startFetching),
                           function($query) use($startFetching){
                               return $query->where('microtimestamp', '>=', $startFetching);
                        })
                    ->when(!is_null($endFetching),
                           function($query) use($endFetching){
                               return $query->where('microtimestamp', '<=', $endFetching);
                        })
                    ->orderBy('microtimestamp');
        if(App::hasDebugModeEnabled()){
            Log::debug("SQL Query: ".$tickers_q->toRawSql());
        }
        $rawTickers = $tickers_q->get()->toArray();
        $tickers = [];
        foreach ($rawTickers as $raw)
        {
            $tickers[$raw['microtimestamp']] = json_decode($raw['payload'], true);
        }

        return $tickers;
    }

    // array_merge breaks the keys
    public function fixTickerIndex(array $tickers): array
    {
        $nt = array();
        // Use $ticker[microtimestamp] as index
        foreach ($tickers as &$ticker){
            $nt[$ticker['microtimestamp']] = &$ticker;
            unset($nt[$ticker['microtimestamp']][0]);
        }
        $tickers = $nt;
        return $tickers;
    }

    /**
     * @return string
     *  Return the model
     */
    public function model()
    {
        return Ticker::class;
    }

    public function update(array $data, array $unique, array $update)
    {
        //NOTE: workaround, it seems that upsert doesn't use the Trait
        foreach ($data as &$d)
        {
            $d['ticker_id'] = (string)Str::uuid7();
        }
        $newTickers = Ticker::upsert($data, $unique, $update);
        return $newTickers;
    }
    public function updateTickers(string $exchange, string $symbol, string $period, array $tickers)
    {
        $unique = ['exchange','symbol','period','microtimestamp'];
        $update = ['payload'];

        foreach (array_chunk($tickers, 100) as $chunk)
        {
            $data = [];
            foreach ($chunk as $ticker)
            {
                $data1['exchange'] = $exchange;
                $data1['symbol'] = $symbol;
                $data1['period'] = $period;
                $data1['microtimestamp'] = $ticker['microtimestamp'];
                $data1['payload'] = json_encode($ticker);
                $data[] = $data1;
            }
            $this->update($data, $unique, $update);
        }

    }

    public function saveTickers(string $exchange, string $symbol, string $period, array $tickers)
    {
        $unique = ['exchange','symbol','period','microtimestamp'];
        $update = ['payload'];

        foreach (array_chunk($tickers, 100) as $chunk)
        {
            $data = [];
            foreach ($chunk as $ticker)
            {
                $data1['exchange'] = $exchange;
                $data1['symbol'] = $symbol;
                $data1['period'] = $period;
                $data1['microtimestamp'] = $ticker['microtimestamp'];
                $data1['payload'] = json_encode($ticker);
                $data[] = $data1;
            }

            Ticker::insertOrIgnore($data);
        }

    }

    public function setExchange(?\ccxt\Exchange $exchange)
    {
        $this->ccxtExchange = $exchange;
    }

    public function setTicker(Ticker $ticker)
    {
        $this->ticker = $ticker;
    }
}
