<?php

namespace App\Repositories;

use App\Domain\MarketData\OhlcvNormalizer;
use App\Models\Ticker;
use ccxt\Exchange;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JasonGuru\LaravelMakeRepository\Repository\BaseRepository;

/**
 * Class TickerRepository.
 */
class TickerRepository extends BaseRepository
{
    protected ?Ticker $ticker;

    protected ?Exchange $ccxtExchange = null;

    public function __construct(?Ticker $ticker = null)
    {
        parent::__construct();
        $this->ticker = $ticker;
    }

    public function fetch(string $symbol, string $period, int $startFetching, int $records, array $params): array
    {
        $myTickers = $this->ccxtExchange->fetch_ohlcv($symbol, $period, $startFetching, $records, $params);

        if (App::hasDebugModeEnabled()) {
            Log::debug('myTickers: '.print_r($myTickers, true));
        }

        return (new OhlcvNormalizer)->normalize($myTickers);
    }

    public function fetchFromDB(string $exchange, string $symbol, string $period, ?int $startFetching = null, ?int $endFetching = null): array
    {
        $tickers_q = Ticker::where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('period', $period)
            ->when(! is_null($startFetching), function ($query) use ($startFetching) {
                return $query->where('microtimestamp', '>=', $startFetching);
            })
            ->when(! is_null($endFetching), function ($query) use ($endFetching) {
                return $query->where('microtimestamp', '<=', $endFetching);
            })
            ->orderBy('microtimestamp');

        if (App::hasDebugModeEnabled()) {
            Log::debug('SQL Query: '.$tickers_q->toRawSql());
        }

        $rawTickers = $tickers_q->get()->toArray();
        $tickers = [];

        foreach ($rawTickers as $raw) {
            $tickers[] = json_decode($raw['payload'], true, flags: JSON_THROW_ON_ERROR);
        }

        return $tickers;
    }

    // array_merge breaks timestamp keys, so normalize and reindex canonically.
    public function fixTickerIndex(array $tickers): array
    {
        return (new OhlcvNormalizer)->normalize($tickers, true);
    }

    /**
     * @return string Return the model
     */
    public function model(): string
    {
        return Ticker::class;
    }

    public function update(array $data, array $unique, array $update): int
    {
        // Eloquent's bulk upsert bypasses model creating events. Generate UUIDs
        // before the insert path, while preserving explicitly supplied IDs.
        foreach ($data as &$row) {
            if (empty($row['ticker_id'])) {
                $row['ticker_id'] = (string) Str::uuid7();
            }
        }
        unset($row);

        return Ticker::query()->upsert($data, $unique, $update);
    }

    public function updateTickers(string $exchange, string $symbol, string $period, array $tickers): int
    {
        $unique = ['exchange', 'symbol', 'period', 'microtimestamp'];
        $update = ['payload'];
        $affected = 0;

        foreach (array_chunk($tickers, 100) as $chunk) {
            $data = [];

            foreach ($chunk as $ticker) {
                $data[] = [
                    'exchange' => $exchange,
                    'symbol' => $symbol,
                    'period' => $period,
                    'microtimestamp' => $ticker['microtimestamp'],
                    'payload' => json_encode($ticker, JSON_THROW_ON_ERROR),
                ];
            }

            $affected += $this->update($data, $unique, $update);
        }

        return $affected;
    }

    public function saveTickers(string $exchange, string $symbol, string $period, array $tickers): int
    {
        return $this->updateTickers($exchange, $symbol, $period, $tickers);
    }

    public function setExchange(?Exchange $exchange): void
    {
        $this->ccxtExchange = $exchange;
    }

    public function setTicker(Ticker $ticker): void
    {
        $this->ticker = $ticker;
    }
}
