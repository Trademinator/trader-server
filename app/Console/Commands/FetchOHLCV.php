<?php

namespace App\Console\Commands;

use App\Domain\MarketData\ExchangeMetadata;
use App\Repositories\ExchangeRepository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Contracts\Console\PromptsForMissingInput;

use function Trademinator\Time\to_unixtime;

class FetchOHLCV extends Command implements Isolatable, PromptsForMissingInput
{
    protected ExchangeRepository $exchangeRepository;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trademinator:fetch-ohlcv {exchange} {symbol} {period} {from?} {to?} {--debug}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch OHLCV information from a specific Exchange-Symbol-Period from a specific time frame';

    public function __construct(ExchangeRepository $exchangeRepository)
    {
        parent::__construct();
        $this->exchangeRepository = $exchangeRepository;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $className = $this->argument('exchange');
        $symbol = $this->argument('symbol');
        $period = $this->argument('period');
        if ($this->exchangeRepository->hasExchange($className)) {

            $debugExchange = $this->option('debug');
            $extraSettings = [];
            $extraSettings['verbose'] = $debugExchange;
            $exchanges = $this->exchangeRepository->findByClass($className);
            $startTime = to_unixtime($this->argument('from') ?? 'yesterday');
            // if {to} is not specified, then is today
            $endtTime = to_unixtime($this->argument('to') ?? 'now');

            if ($exchanges->isEmpty()) {
                $this->error('The exchange is not configured.');

                return self::FAILURE;
            }
            foreach ($exchanges as $exchange) {
                app(ExchangeMetadata::class)->assertUsable($exchange, spotOnly: false, symbol: $symbol);
                $this->exchangeRepository->setExchange($exchange, $extraSettings, symbol: $symbol);
                if (! array_key_exists($period, $this->exchangeRepository->periods())) {
                    $this->error('The exchange does not support this candle period.');

                    return self::FAILURE;
                }
                $this->exchangeRepository->prepareCandleMarket($symbol);
                $tickers = $this->exchangeRepository->fetch($symbol, $period, $startTime, $endtTime);

                $colour = new \Console_Color2;
                foreach ($tickers as $ticker) {
                    $line = $ticker['human_date'].': '.'; open: '.$ticker['open'].'; high: '.$ticker['high'].'; low: '.$ticker['low'].'; close: '.$ticker['close'].'; volume: '.$ticker['volume'];
                    echo $colour->convert('%B'.$line.'%n').PHP_EOL;
                }
                // print_r($tickers);
                $this->info('The command was successful!');
            }
        } else {
            $this->error($className.'-'.$symbol.'-'.$period.' tuple is not supported.');

            return 1;
        }
    }
}
