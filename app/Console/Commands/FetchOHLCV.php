<?php

namespace App\Console\Commands;

use App\Models\Exchange;
use App\Repositories\ExchangeRepository;
use App\Traits\IsSupportedByCCXT;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use function Trademinator\Time\to_unixtime;
use function Trademinator\Time\yesterday_unixtime;

class FetchOHLCV extends Command implements Isolatable, PromptsForMissingInput
{
    use IsSupportedByCCXT;
    protected ExchangeRepository $exchangeRepository;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trademinator:FetchOHLCV {exchange} {symbol} {period} {from?} {to?} {--debug}';

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
        if ($this->isSupportedByCCXT($className, $symbol, $period))
        {

            $debugExchange = $this->option('debug'); $extraSettings = [];
            $extraSettings['verbose'] = $debugExchange;
            $exchanges = $this->exchangeRepository->findByClass($className);
            $startTime =  to_unixtime($this->argument('from') ?? 'yester4day');
            // if {to} is not specified, then is today
            $endtTime = to_unixtime($this->argument('to') ?? 'now');

            foreach ($exchanges as $exchange)
            {
                $this->exchangeRepository->setExchange($exchange, $extraSettings);
                $tickers = $this->exchangeRepository->fetch($symbol, $period, $startTime, $endtTime);

                $colour = new \Console_Color2();
                foreach ($tickers as $ticker)
                {
                    $line = $ticker['human_date'] . ': ' . '; open: ' . $ticker['open'] . '; high: ' . $ticker['high'] . '; low: ' . $ticker['low'] . '; close: ' . $ticker['close'] . '; volume: ' . $ticker['volume'];
                    echo $colour->convert('%B'.$line.'%n').PHP_EOL;
                }
                //print_r($tickers);
                $this->info('The command was successful!');
            }
        }
        else
        {
            $this->error($className . '-' . $symbol . '-' . $period . ' tuple is not supported.');
            return 1;
        }
    }
}
